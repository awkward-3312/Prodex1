@extends('central.super.layout')

@section('title', __('super.plans.create_title'))

@section('content')
<div class="breadcrumb-custom">
    <a href="{{ route('super.plans.index') }}">{{ __('super.plans.title') }}</a>
    <span class="separator"><i class="bi bi-chevron-right"></i></span>
    <span class="current">{{ __('super.plans.create_plan') }}</span>
</div>

<div class="page-header">
    <h1>{{ __('super.plans.create_plan') }}</h1>
    <p class="page-subtitle">{{ __('super.plans.create_subtitle') }}</p>
</div>

<form method="POST" action="{{ route('super.plans.store') }}">
    @csrf

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
                            <input type="text" name="name" class="form-control" value="{{ old('name') }}" placeholder="{{ __('super.plans.plan_name_hint') }}" required>
                        </div>
                        <div class="form-group">
                            <label class="form-label">{{ __('super.plans.slug') }}</label>
                            <input type="text" name="slug" class="form-control text-mono" value="{{ old('slug') }}" placeholder="{{ __('super.plans.slug_auto') }}">
                            <p class="form-hint">{{ __('super.plans.slug_hint') }}</p>
                        </div>
                    </div>
                    <div class="form-row">
                        <div class="form-group">
                            <label class="form-label">{{ __('super.plans.monthly_price') }} <span class="text-danger">*</span></label>
                            <div class="price-input-wrap">
                                <span class="price-input-symbol">{{ $currencySymbol }}</span>
                                <input type="number" name="price" class="form-control price-input" step="0.01" min="0" value="{{ old('price', 0) }}" required>
                            </div>
                            <p class="form-hint">{{ __('super.plans.monthly_price_hint') }}</p>
                        </div>
                        <div class="form-group">
                            <label class="form-label">{{ __('super.plans.yearly_price') }} <span class="text-danger">*</span></label>
                            <div class="price-input-wrap">
                                <span class="price-input-symbol">{{ $currencySymbol }}</span>
                                <input type="number" name="yearly_price" class="form-control price-input" step="0.01" min="0" value="{{ old('yearly_price', 0) }}" required>
                            </div>
                            <p class="form-hint">{{ __('super.plans.yearly_price_hint') }}</p>
                        </div>
                    </div>
                    <div class="form-group">
                        <label class="form-switch-label">
                            <input type="checkbox" name="is_active" value="1" class="form-check-input" {{ old('is_active', true) ? 'checked' : '' }}>
                            <span>{{ __('super.common.active') }}</span>
                        </label>
                        <p class="form-hint">{{ __('super.plans.active_hint') }}</p>
                    </div>
                    <div class="form-group">
                        <label class="form-switch-label">
                            <input type="checkbox" name="is_private" value="1" class="form-check-input" {{ old('is_private') ? 'checked' : '' }}>
                            <span><i class="bi bi-lock me-1"></i>Private Plan</span>
                        </label>
                        <p class="form-hint">Private plans are hidden from the landing page, registration form, and tenant billing page. Only a super admin can assign them to tenants.</p>
                    </div>
                    <div class="form-group">
                        <label class="form-switch-label">
                            <input type="checkbox" name="is_trial" value="1" class="form-check-input" id="isTrialToggle" {{ old('is_trial') ? 'checked' : '' }}>
                            <span>{{ __('super.plans.free_trial') }}</span>
                        </label>
                        <p class="form-hint">{{ __('super.plans.free_trial_hint') }}</p>
                    </div>
                    <div class="form-group" id="trialDaysGroup" style="{{ old('is_trial') ? '' : 'display:none;' }}">
                        <label class="form-label">{{ __('super.plans.trial_days') }}</label>
                        <input type="number" name="trial_days" class="form-control max-w-160" value="{{ old('trial_days', 14) }}" min="1" max="365">
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
                                       value="{{ old('limits.' . $key) }}"
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
                    <div class="features-grid">
                        @foreach($availableFeatures as $key => $meta)
                        <label class="feature-card" for="feature_{{ $key }}">
                            <input type="checkbox"
                                   name="features[]"
                                   value="{{ $key }}"
                                   id="feature_{{ $key }}"
                                   class="feature-checkbox"
                                   {{ in_array($key, old('features', [])) ? 'checked' : '' }}>
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

            <div class="d-flex gap-2">
                <button type="submit" class="btn btn-primary">
                    <i class="bi bi-plus-lg"></i> {{ __('super.plans.create_btn') }}
                </button>
                <a href="{{ route('super.plans.index') }}" class="btn btn-secondary">{{ __('super.common.cancel') }}</a>
            </div>
        </div>

        {{-- Right column --}}
        <div class="col-lg-4">
            <div class="content-card mb-4 sticky-sidebar">
                <div class="card-body-custom">
                    <div class="d-flex align-items-center gap-2 mb-3">
                        <i class="bi bi-lightbulb text-muted fs-base"></i>
                        <h3 class="mb-0 fw-700 tips-card-title">{{ __('super.plans.how_limits_work') }}</h3>
                    </div>
                    <div class="d-flex flex-column gap-3 tips-card-body">
                        <div class="d-flex gap-2">
                            <i class="bi bi-check2-circle tips-icon-success"></i>
                            <span>{!! __('super.plans.limits_info_1') !!}</span>
                        </div>
                        <div class="d-flex gap-2">
                            <i class="bi bi-check2-circle tips-icon-success"></i>
                            <span>{!! __('super.plans.limits_info_2') !!}</span>
                        </div>
                        <div class="d-flex gap-2">
                            <i class="bi bi-check2-circle tips-icon-success"></i>
                            <span>{{ __('super.plans.limits_info_3') }}</span>
                        </div>
                        <div class="d-flex gap-2">
                            <i class="bi bi-check2-circle tips-icon-success"></i>
                            <span>{{ __('super.plans.limits_info_4') }}</span>
                        </div>
                        <div class="d-flex gap-2">
                            <i class="bi bi-check2-circle tips-icon-success"></i>
                            <span>{{ __('super.plans.limits_info_5') }}</span>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</form>

@push('scripts')
<script>
window.PlansFormData = { unlimitedLabel: @json(__('super.common.unlimited')) };
</script>
<script src="{{ asset('assets_super/js/plans-form.js') }}"></script>
@endpush
@endsection
