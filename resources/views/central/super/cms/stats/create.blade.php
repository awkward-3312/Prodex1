@extends('central.super.layout')

@section('title', __('super.stats.add_title'))

@section('content')
<div class="breadcrumb-custom">
    <a href="{{ route('super.cms.index') }}">CMS</a>
    <span class="separator"><i class="bi bi-chevron-right"></i></span>
    <a href="{{ route('super.cms.stats') }}">{{ __('super.stats.title') }}</a>
    <span class="separator"><i class="bi bi-chevron-right"></i></span>
    <span class="current">{{ __('super.stats.add_title') }}</span>
</div>

<div class="page-header">
    <h1>{{ __('super.stats.add_title') }}</h1>
    <p class="page-subtitle">{{ __('super.stats.add_subtitle') }}</p>
</div>

<div class="content-card">
    <div class="card-body-custom">
        <form method="POST" action="{{ route('super.cms.stats.store') }}">
            @csrf
            <div class="form-row mb-4">
                <div class="form-group">
                    <label class="form-label">{{ __('super.stats.field_value') }} <span class="text-danger">*</span></label>
                    <input type="text" name="value" class="form-control" value="{{ old('value') }}" placeholder="e.g. 10K+" required>
                    <p class="form-hint">{{ __('super.stats.value_hint') }}</p>
                </div>
                <div class="form-group">
                    <label class="form-label">{{ __('super.stats.field_label') }} <span class="text-danger">*</span></label>
                    <input type="text" name="label" class="form-control" value="{{ old('label') }}" placeholder="e.g. Businesses" required>
                </div>
            </div>
            <div class="form-row mb-4">
                <div class="form-group">
                    <label class="form-label">{{ __('super.stats.field_icon') }}</label>
                    <input type="text" name="icon" class="form-control" value="{{ old('icon') }}" placeholder="e.g. bi bi-building">
                    <p class="form-hint">{{ __('super.stats.icon_hint') }}</p>
                </div>
                <div class="form-group">
                    <label class="form-label">{{ __('super.common.sort_order') }}</label>
                    <input type="number" name="sort_order" class="form-control" value="{{ old('sort_order', 0) }}" min="0">
                </div>
            </div>
            <div class="form-group mb-4">
                <label class="form-switch-label">
                    <input type="checkbox" name="is_active" value="1" class="form-check-input" {{ old('is_active', true) ? 'checked' : '' }}>
                    <span>{{ __('super.common.active') }}</span>
                </label>
            </div>
            <div class="divider"></div>
            <div class="d-flex gap-2">
                <button type="submit" class="btn btn-primary">{{ __('super.common.create') }}</button>
                <a href="{{ route('super.cms.stats') }}" class="btn btn-secondary">{{ __('super.common.cancel') }}</a>
            </div>
        </form>
    </div>
</div>
@endsection
