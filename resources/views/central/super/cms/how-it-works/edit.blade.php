@extends('central.super.layout')

@section('title', __('super.how_it_works.edit_title'))

@section('content')
<div class="breadcrumb-custom">
    <a href="{{ route('super.cms.index') }}">CMS</a>
    <span class="separator"><i class="bi bi-chevron-right"></i></span>
    <a href="{{ route('super.cms.how-it-works') }}">{{ __('super.how_it_works.title') }}</a>
    <span class="separator"><i class="bi bi-chevron-right"></i></span>
    <span class="current">{{ $step->title }}</span>
</div>

<div class="page-header">
    <h1>{{ __('super.how_it_works.edit_title') }}</h1>
    <p class="page-subtitle">{{ $step->title }}</p>
</div>

<div class="content-card">
    <div class="card-body-custom">
        <form method="POST" action="{{ route('super.cms.how-it-works.update', $step) }}">
            @csrf
            @method('PUT')
            <div class="form-group mb-4">
                <label class="form-label">{{ __('super.how_it_works.field_title') }} <span class="text-danger">*</span></label>
                <input type="text" name="title" class="form-control" value="{{ old('title', $step->title) }}" required>
            </div>
            <div class="form-group mb-4">
                <label class="form-label">{{ __('super.how_it_works.field_desc') }}</label>
                <textarea name="description" class="form-control" rows="3">{{ old('description', $step->description) }}</textarea>
            </div>
            <div class="form-row mb-4">
                <div class="form-group">
                    <label class="form-label">{{ __('super.how_it_works.field_icon') }}</label>
                    <input type="text" name="icon" class="form-control" value="{{ old('icon', $step->icon) }}" placeholder="e.g. bi bi-arrow-repeat">
                    <p class="form-hint">{{ __('super.how_it_works.icon_hint') }}</p>
                </div>
                <div class="form-group">
                    <label class="form-label">{{ __('super.how_it_works.field_color') }}</label>
                    <select name="icon_color" class="form-control">
                        @php $color = old('icon_color', $step->icon_color); @endphp
                        <option value="green" {{ $color === 'green' ? 'selected' : '' }}>Green</option>
                        <option value="blue" {{ $color === 'blue' ? 'selected' : '' }}>Blue</option>
                        <option value="amber" {{ $color === 'amber' ? 'selected' : '' }}>Amber</option>
                        <option value="purple" {{ $color === 'purple' ? 'selected' : '' }}>Purple</option>
                        <option value="teal" {{ $color === 'teal' ? 'selected' : '' }}>Teal</option>
                        <option value="red" {{ $color === 'red' ? 'selected' : '' }}>Red</option>
                    </select>
                </div>
            </div>
            <div class="form-group mb-4">
                <label class="form-label">{{ __('super.common.sort_order') }}</label>
                <input type="number" name="sort_order" class="form-control" value="{{ old('sort_order', $step->sort_order) }}" min="0">
            </div>
            <div class="form-group mb-4">
                <label class="form-switch-label">
                    <input type="checkbox" name="is_active" value="1" class="form-check-input" {{ old('is_active', $step->is_active) ? 'checked' : '' }}>
                    <span>{{ __('super.common.active') }}</span>
                </label>
            </div>
            <div class="divider"></div>
            <div class="d-flex gap-2">
                <button type="submit" class="btn btn-primary">{{ __('super.common.update') }}</button>
                <a href="{{ route('super.cms.how-it-works') }}" class="btn btn-secondary">{{ __('super.common.cancel') }}</a>
            </div>
        </form>
    </div>
</div>
@endsection
