@extends('central.super.layout')

@section('title', __('super.features.edit_title'))

@section('content')
<div class="breadcrumb-custom">
    <a href="{{ route('super.cms.index') }}">CMS</a>
    <span class="separator"><i class="bi bi-chevron-right"></i></span>
    <a href="{{ route('super.cms.features') }}">{{ __('super.features.title') }}</a>
    <span class="separator"><i class="bi bi-chevron-right"></i></span>
    <span class="current">{{ $feature->title }}</span>
</div>

<div class="page-header">
    <h1>{{ __('super.features.edit_title') }}</h1>
    <p class="page-subtitle">{{ $feature->title }}</p>
</div>

<div class="content-card">
    <div class="card-body-custom">
        <form method="POST" action="{{ route('super.cms.features.update', $feature) }}" enctype="multipart/form-data">
            @csrf
            @method('PUT')
            <div class="form-group mb-4">
                <label class="form-label">{{ __('super.features.field_title') }} <span class="text-danger">*</span></label>
                <input type="text" name="title" class="form-control" value="{{ old('title', $feature->title) }}" required>
            </div>
            <div class="form-group mb-4">
                <label class="form-label">{{ __('super.features.field_desc') }}</label>
                <textarea name="description" class="form-control" rows="3">{{ old('description', $feature->description) }}</textarea>
            </div>
            <div class="form-row mb-4">
                <div class="form-group">
                    <label class="form-label">{{ __('super.features.icon_class') }}</label>
                    <input type="text" name="icon" class="form-control" value="{{ old('icon', $feature->icon) }}" placeholder="e.g. bi-box-seam">
                </div>
                <div class="form-group">
                    <label class="form-label">{{ __('super.features.or_upload') }}</label>
                    <input type="file" name="image" class="form-control" accept="image/*">
                    @if($feature->image)
                        <p class="form-hint mt-1">{{ __('super.common.current') }}: <a href="{{ asset($feature->image) }}" target="_blank">{{ __('super.common.view') }}</a></p>
                    @endif
                </div>
            </div>
            <div class="form-group mb-4">
                <label class="form-label">{{ __('super.common.sort_order') }}</label>
                <input type="number" name="sort_order" class="form-control" value="{{ old('sort_order', $feature->sort_order) }}" min="0">
            </div>
            <div class="form-group mb-4">
                <label class="form-switch-label">
                    <input type="checkbox" name="is_active" value="1" class="form-check-input" {{ old('is_active', $feature->is_active) ? 'checked' : '' }}>
                    <span>{{ __('super.common.active') }}</span>
                </label>
            </div>
            <div class="divider"></div>
            <div class="d-flex gap-2">
                <button type="submit" class="btn btn-primary">{{ __('super.common.update') }}</button>
                <a href="{{ route('super.cms.features') }}" class="btn btn-secondary">{{ __('super.common.cancel') }}</a>
            </div>
        </form>
    </div>
</div>
@endsection
