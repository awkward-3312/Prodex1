@extends('central.super.layout')

@section('title', __('super.plans.edit_title'))

@section('content')
<div class="breadcrumb-custom">
    <a href="{{ route('super.plans.index') }}">{{ __('super.plans.title') }}</a>
    <span class="separator"><i class="bi bi-chevron-right"></i></span>
    <span class="current">{{ $plan->name }}</span>
</div>

<div class="page-header d-flex flex-wrap align-items-center justify-content-between gap-3">
    <div>
        <h1>{{ __('super.plans.edit_plan') }}</h1>
        <p class="page-subtitle">{{ __('super.plans.edit_subtitle') }} <strong>{{ $plan->name }}</strong>.</p>
    </div>
    <div class="d-flex align-items-center gap-2">
        @if($plan->tenant_subscriptions_count > 0)
            <span class="badge badge-trial fs-xs3">
                {{ $plan->tenant_subscriptions_count }} {{ Str::plural(__('super.common.subscriber'), $plan->tenant_subscriptions_count) }}
            </span>
        @endif
        <span class="badge badge-{{ $plan->is_active ? 'yes' : 'no' }} fs-xs3">{{ $plan->is_active ? __('super.common.active') : __('super.common.inactive') }}</span>
    </div>
</div>

<form method="POST" action="{{ route('super.plans.update', $plan) }}">
    @csrf
    @method('PUT')

    <div class="row g-4">
        {{-- Left column --}}
        <div class="col-lg-8">
            {{-- Plan details --}}
            <div class="content-card mb-4">
                <div class="card-header-custom">
                    <h2><i class="bi bi-tag me-2 text-muted"></i>{{ __('super.plans.plan_details') }}</h2>
                </div>
                <div class="card-body-custom">
                    <div class="form-row">
                        <div class="form-group">
                            <label class="form-label">{{ __('super.plans.plan_name') }} <span class="text-danger">*</span></label>
                            <input type="text" name="name" class="form-control" value="{{ old('name', $plan->name) }}" required>
                        </div>
                        <div class="form-group">
                            <label class="form-label">{{ __('super.plans.slug') }} <span class="text-danger">*</span></label>
                            <input type="text" name="slug" class="form-control text-mono" value="{{ old('slug', $plan->slug) }}" required>
                        </div>
                    </div>
                    <div class="form-row">
                        <div class="form-group">
                            <label class="form-label">{{ __('super.plans.monthly_price') }} <span class="text-danger">*</span></label>
                            <div class="price-input-wrap">
                                <span class="price-input-symbol">{{ $currencySymbol }}</span>
                                <input type="number" name="price" class="form-control price-input" step="0.01" min="0" value="{{ old('price', $plan->price) }}" required>
                            </div>
                            <p class="form-hint">{{ __('super.plans.monthly_price_hint') }}</p>
                        </div>
                        <div class="form-group">
                            <label class="form-label">{{ __('super.plans.yearly_price') }} <span class="text-danger">*</span></label>
                            <div class="price-input-wrap">
                                <span class="price-input-symbol">{{ $currencySymbol }}</span>
                                <input type="number" name="yearly_price" class="form-control price-input" step="0.01" min="0" value="{{ old('yearly_price', $plan->yearly_price) }}" required>
                            </div>
                            <p class="form-hint">{{ __('super.plans.yearly_price_hint') }}</p>
                        </div>
                    </div>
                    <div class="form-group">
                        <label class="form-switch-label">
                            <input type="checkbox" name="is_active" value="1" class="form-check-input" {{ old('is_active', $plan->is_active) ? 'checked' : '' }}>
                            <span>{{ __('super.common.active') }}</span>
                        </label>
                        <p class="form-hint">{{ __('super.plans.inactive_hint') }}</p>
                    </div>
                    <div class="form-group">
                        <label class="form-switch-label">
                            <input type="checkbox" name="is_private" value="1" class="form-check-input" {{ old('is_private', $plan->is_private) ? 'checked' : '' }}>
                            <span><i class="bi bi-lock me-1"></i>Private Plan</span>
                        </label>
                        <p class="form-hint">Private plans are hidden from the landing page, registration form, and tenant billing page. Only a super admin can assign them to tenants.</p>
                    </div>
                    <div class="form-group">
                        <label class="form-switch-label">
                            <input type="checkbox" name="is_trial" value="1" class="form-check-input" id="isTrialToggle" {{ old('is_trial', $plan->is_trial) ? 'checked' : '' }}>
                            <span>{{ __('super.plans.free_trial') }}</span>
                        </label>
                        <p class="form-hint">{{ __('super.plans.free_trial_hint') }}</p>
                    </div>
                    <div class="form-group" id="trialDaysGroup" style="{{ old('is_trial', $plan->is_trial) ? '' : 'display:none;' }}">
                        <label class="form-label">{{ __('super.plans.trial_days') }}</label>
                        <input type="number" name="trial_days" class="form-control max-w-160" value="{{ old('trial_days', $plan->trial_days ?: 14) }}" min="1" max="365">
                    </div>
                </div>
            </div>

            {{-- Usage limits --}}
            <div class="content-card mb-4">
                <div class="card-header-custom">
                    <h2><i class="bi bi-speedometer2 me-2 text-muted"></i>{{ __('super.plans.usage_limits') }}</h2>
                    <span class="text-muted fs-sm">{{ __('super.plans.limits_hint') }}</span>
                </div>
                <div class="card-body-custom">
                    <div class="limits-grid">
                        @foreach($availableLimits as $key => $meta)
                        @php $currentVal = old('limits.' . $key, $plan->getLimit($key)); @endphp
                        <div class="limit-item">
                            <div class="limit-item-header">
                                <div class="limit-icon">
                                    <i class="bi {{ $meta['icon'] }}"></i>
                                </div>
                                <label class="form-label mb-0">{{ $meta['label'] }}</label>
                            </div>
                            <div class="limit-input-wrap">
                                <input type="number"
                                       name="limits[{{ $key }}]"
                                       class="form-control limit-input"
                                       value="{{ $currentVal > 0 ? $currentVal : ($currentVal == -1 ? -1 : '') }}"
                                       placeholder="{{ __('super.common.unlimited') }}"
                                       min="-1">
                                <button type="button" class="limit-unlimited-btn" title="{{ __('super.plans.set_unlimited') }}">
                                    <i class="bi bi-infinity"></i>
                                </button>
                            </div>
                        </div>
                        @endforeach
                    </div>
                </div>
            </div>

            {{-- Features --}}
            <div class="content-card mb-4">
                <div class="card-header-custom">
                    <h2><i class="bi bi-puzzle me-2 text-muted"></i>{{ __('super.plans.features') }}</h2>
                    <span class="text-muted fs-sm">{{ __('super.plans.features_toggle') }}</span>
                </div>
                <div class="card-body-custom">
                    @php $currentFeatures = old('features', $plan->features ?? []); @endphp
                    <div class="features-grid">
                        @foreach($availableFeatures as $key => $meta)
                        <label class="feature-card" for="feature_{{ $key }}">
                            <input type="checkbox"
                                   name="features[]"
                                   value="{{ $key }}"
                                   id="feature_{{ $key }}"
                                   class="feature-checkbox"
                                   {{ in_array($key, $currentFeatures) ? 'checked' : '' }}>
                            <div class="feature-card-inner">
                                <div class="feature-card-icon">
                                    <i class="bi {{ $meta['icon'] }}"></i>
                                </div>
                                <div class="feature-card-body">
                                    <span class="feature-card-title">{{ $meta['label'] }}</span>
                                    <span class="feature-card-desc">{{ $meta['description'] }}</span>
                                </div>
                                <div class="feature-card-toggle">
                                    <i class="bi bi-check-lg"></i>
                                </div>
                            </div>
                        </label>
                        @endforeach
                    </div>
                </div>
            </div>

            <div class="d-flex gap-2 mb-4">
                <button type="submit" class="btn btn-primary">
                    <i class="bi bi-check-lg"></i> {{ __('super.plans.update_btn') }}
                </button>
                <a href="{{ route('super.plans.index') }}" class="btn btn-secondary">{{ __('super.common.cancel') }}</a>
            </div>
        </div>

        {{-- Right column --}}
        <div class="col-lg-4">
            {{-- Plan summary --}}
            <div class="content-card mb-4 sticky-sidebar">
                <div class="card-body-custom">
                    <div class="d-flex align-items-center gap-2 mb-3">
                        <i class="bi bi-info-circle text-muted fs-lg2"></i>
                        <h3 class="mb-0 fw-700 fs-md">{{ __('super.plans.plan_summary') }}</h3>
                    </div>
                    <ul class="info-list fs-sm3">
                        <li>
                            <span class="info-label info-label-min">{{ __('super.common.name') }}</span>
                            <span class="info-value">{{ $plan->name }}</span>
                        </li>
                        <li>
                            <span class="info-label info-label-min">{{ __('super.plans.monthly') }}</span>
                            <span class="info-value">{{ $currencySymbol }}{{ number_format($plan->price, 2) }} / {{ __('super.plans.mo') }}</span>
                        </li>
                        <li>
                            <span class="info-label info-label-min">{{ __('super.plans.yearly') }}</span>
                            <span class="info-value">{{ $currencySymbol }}{{ number_format($plan->yearly_price, 2) }} / {{ __('super.plans.yr') }}</span>
                        </li>
                        <li>
                            <span class="info-label info-label-min">{{ __('super.plans.limits') }}</span>
                            <span class="info-value">{{ $plan->getConfiguredLimitsCount() }} {{ __('super.common.configured') }}</span>
                        </li>
                        <li>
                            <span class="info-label info-label-min">{{ __('super.plans.features') }}</span>
                            <span class="info-value">{{ count($plan->features ?? []) }} {{ __('super.common.enabled') }}</span>
                        </li>
                        <li>
                            <span class="info-label info-label-min">{{ __('super.common.subscribers') }}</span>
                            <span class="info-value">{{ $plan->tenant_subscriptions_count }}</span>
                        </li>
                    </ul>

                    @if(count($plan->features ?? []) > 0)
                    <div class="mt-3 pt-3 border-top-light">
                        <p class="form-label mb-2 fs-xs3">{{ __('super.plans.active_features') }}</p>
                        <div class="d-flex flex-wrap gap-1">
                            @foreach($plan->getActiveFeatures() as $fKey => $fMeta)
                            <span class="badge badge-active badge-feature">
                                <i class="bi {{ $fMeta['icon'] }} badge-feature"></i>
                                {{ $fMeta['label'] }}
                            </span>
                            @endforeach
                        </div>
                    </div>
                    @endif
                </div>
            </div>
        </div>
    </div>
</form>

{{-- Danger zone (outside form) --}}
<div class="row">
    <div class="col-lg-8">
        <div class="content-card danger-zone-card">
            <div class="card-header-custom danger-zone-header">
                <h2><i class="bi bi-exclamation-triangle me-2"></i>{{ __('super.plans.danger_zone') }}</h2>
            </div>
            <div class="card-body-custom">
                <div class="d-flex align-items-center justify-content-between flex-wrap gap-3">
                    <div>
                        <p class="fw-600 mb-1 fs-md">{{ __('super.plans.delete_plan') }}</p>
                        <p class="text-muted mb-0 fs-sm2">{{ __('super.plans.delete_warning') }}</p>
                    </div>
                    <form method="POST" action="{{ route('super.plans.destroy', $plan) }}" id="deletePlanForm" data-swal-confirm="{{ json_encode(['title' => __('super.plans.delete_plan'), 'text' => __('super.plans.delete_confirm'), 'icon' => 'warning', 'confirmButtonText' => __('super.plans.delete_btn'), 'confirmButtonColor' => '#ef4444']) }}">
                        @csrf
                        @method('DELETE')
                        <button type="submit" class="btn btn-danger btn-sm" {{ $plan->tenant_subscriptions_count > 0 ? 'disabled' : '' }}>
                            <i class="bi bi-trash"></i> {{ __('super.plans.delete_btn') }}
                        </button>
                    </form>
                </div>
            </div>
        </div>
    </div>
</div>

@push('scripts')
<script>
window.PlansFormData = { unlimitedLabel: @json(__('super.common.unlimited')) };
</script>
<script src="{{ asset('assets_super/js/plans-form.js') }}"></script>
@endpush
@endsection
