@extends('central.super.layout')

@section('title', __('super.pricing.title'))

@section('content')
<div class="breadcrumb-custom">
    <a href="{{ route('super.cms.index') }}">CMS</a>
    <span class="separator"><i class="bi bi-chevron-right"></i></span>
    <span class="current">{{ __('super.pricing.title') }}</span>
</div>

<div class="page-header">
    <h1>{{ __('super.pricing.title') }}</h1>
    <p class="page-subtitle">{{ __('super.pricing.subtitle') }}</p>
</div>

<div class="row g-4">
    <div class="col-lg-8">
        <div class="content-card">
            <div class="card-header-custom">
                <h2><i class="bi bi-currency-dollar me-2 text-muted"></i>{{ __('super.pricing.settings') }}</h2>
            </div>
            <div class="card-body-custom">
                <form method="POST" action="{{ route('super.cms.pricing.update') }}">
                    @csrf
                    @method('PUT')

                    <div class="form-group mb-4">
                        <label class="form-label">{{ __('super.pricing.section_title') }}</label>
                        <input type="text" name="section_title" class="form-control" value="{{ old('section_title', $pricing->section_title) }}" placeholder="e.g. Simple, transparent pricing">
                    </div>

                    <div class="form-group mb-4">
                        <label class="form-label">{{ __('super.pricing.section_subtitle') }}</label>
                        <textarea name="section_subtitle" class="form-control" rows="2" placeholder="e.g. Start free. Scale when you're ready.">{{ old('section_subtitle', $pricing->section_subtitle) }}</textarea>
                    </div>

                    <div class="divider"></div>

                    <div class="form-group mb-3">
                        <label class="form-switch-label">
                            <input type="checkbox" name="show_monthly_pricing" value="1" class="form-check-input" {{ old('show_monthly_pricing', $pricing->show_monthly_pricing) ? 'checked' : '' }}>
                            <span>{{ __('super.pricing.show_monthly') }}</span>
                        </label>
                    </div>

                    <div class="form-group mb-3">
                        <label class="form-switch-label">
                            <input type="checkbox" name="show_yearly_pricing" value="1" class="form-check-input" {{ old('show_yearly_pricing', $pricing->show_yearly_pricing) ? 'checked' : '' }}>
                            <span>{{ __('super.pricing.show_yearly') }}</span>
                        </label>
                    </div>

                    <div class="form-group mb-3">
                        <label class="form-switch-label">
                            <input type="checkbox" name="load_plans_from_database" value="1" class="form-check-input" {{ old('load_plans_from_database', $pricing->load_plans_from_database) ? 'checked' : '' }}>
                            <span>{{ __('super.pricing.load_from_db') }}</span>
                        </label>
                        <p class="form-hint">{{ __('super.pricing.load_hint') }}</p>
                    </div>

                    <div class="form-group mb-4">
                        <label class="form-switch-label">
                            <input type="checkbox" name="is_active" value="1" class="form-check-input" {{ old('is_active', $pricing->is_active) ? 'checked' : '' }}>
                            <span>{{ __('super.pricing.show_section') }}</span>
                        </label>
                    </div>

                    <div class="divider"></div>
                    <div class="d-flex gap-2">
                        <button type="submit" class="btn btn-primary"><i class="bi bi-check-lg"></i> {{ __('super.common.save') }}</button>
                        <a href="{{ route('central.welcome') }}" target="_blank" class="btn btn-secondary"><i class="bi bi-box-arrow-up-right"></i> {{ __('super.common.preview') }}</a>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <div class="col-lg-4">
        <div class="content-card">
            <div class="card-body-custom">
                <div class="d-flex align-items-center gap-2 mb-3">
                    <i class="bi bi-lightbulb text-muted fs-lg2"></i>
                    <h3 class="mb-0 fw-700 fs-md">{{ __('super.pricing.tips') }}</h3>
                </div>
                <div class="d-flex flex-column gap-3 tips-card-body">
                    <div class="d-flex gap-2">
                        <i class="bi bi-check2-circle tip-check-icon"></i>
                        <span>{{ __('super.pricing.tip_1') }}</span>
                    </div>
                    <div class="d-flex gap-2">
                        <i class="bi bi-check2-circle tip-check-icon"></i>
                        <span>{{ __('super.pricing.tip_2') }}</span>
                    </div>
                    <div class="d-flex gap-2">
                        <i class="bi bi-check2-circle tip-check-icon"></i>
                        <span>{{ __('super.pricing.tip_3_prefix') }} <a href="{{ route('super.plans.index') }}">{{ __('super.pricing.tip_3_link') }}</a> {{ __('super.pricing.tip_3_suffix') }}</span>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>
@endsection
