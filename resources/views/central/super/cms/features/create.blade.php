@extends('central.super.layout')

@section('title', __('super.features.add_title'))

@section('content')
<div class="breadcrumb-custom">
    <a href="{{ route('super.cms.index') }}">CMS</a>
    <span class="separator"><i class="bi bi-chevron-right"></i></span>
    <a href="{{ route('super.cms.features') }}">{{ __('super.features.title') }}</a>
    <span class="separator"><i class="bi bi-chevron-right"></i></span>
    <span class="current">{{ __('super.features.add_title') }}</span>
</div>

<div class="page-header">
    <h1>{{ __('super.features.add_title') }}</h1>
    <p class="page-subtitle">{{ __('super.features.add_subtitle') }}</p>
</div>

<div class="content-card">
    <div class="card-body-custom">
        <form method="POST" action="{{ route('super.cms.features.store') }}" enctype="multipart/form-data">
            @csrf
            <div class="form-group mb-4">
                <label class="form-label">{{ __('super.features.field_title') }} <span class="text-danger">*</span></label>
                <input type="text" name="title" class="form-control" value="{{ old('title') }}" required>
            </div>
            <div class="form-group mb-4">
                <label class="form-label">{{ __('super.features.field_desc') }}</label>
                <textarea name="description" class="form-control" rows="3">{{ old('description') }}</textarea>
            </div>
            <div class="form-row mb-4">
                <div class="form-group">
                    <label class="form-label">{{ __('super.features.icon_class') }}</label>
                    <input type="text" name="icon" class="form-control" value="{{ old('icon') }}" placeholder="e.g. bi-box-seam">
                    <p class="form-hint">{{ __('super.features.icon_hint') }}</p>
                </div>
                <div class="form-group">
                    <label class="form-label">{{ __('super.features.or_upload') }}</label>
                    <input type="file" name="image" class="form-control" accept="image/*">
                    <p class="form-hint">{{ __('super.features.image_precedence') }}</p>
                </div>
            </div>
            <div class="form-group mb-4">
                <label class="form-label">{{ __('super.common.sort_order') }}</label>
                <input type="number" name="sort_order" class="form-control" value="{{ old('sort_order', 0) }}" min="0">
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
                <a href="{{ route('super.cms.features') }}" class="btn btn-secondary">{{ __('super.common.cancel') }}</a>
            </div>
        </form>
    </div>
</div>
@endsection
