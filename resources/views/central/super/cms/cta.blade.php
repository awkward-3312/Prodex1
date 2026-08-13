@extends('central.super.layout')

@section('title', __('super.cta.page_title'))

@section('content')
<div class="breadcrumb-custom">
    <a href="{{ route('super.cms.index') }}">CMS</a>
    <span class="separator"><i class="bi bi-chevron-right"></i></span>
    <span class="current">{{ __('super.cta.page_title') }}</span>
</div>

<div class="page-header">
    <h1>{{ __('super.cta.title') }}</h1>
    <p class="page-subtitle">{{ __('super.cta.subtitle') }}</p>
</div>

<div class="row g-4">
    <div class="col-lg-8">
        <div class="content-card">
            <div class="card-header-custom">
                <h2><i class="bi bi-megaphone me-2 text-muted"></i>{{ __('super.cta.content') }}</h2>
            </div>
            <div class="card-body-custom">
                <form method="POST" action="{{ route('super.cms.cta.update') }}" enctype="multipart/form-data">
                    @csrf
                    @method('PUT')

                    <div class="form-group mb-4">
                        <label class="form-label">{{ __('super.cta.field_title') }} <span class="text-danger">*</span></label>
                        <input type="text" name="title" class="form-control" value="{{ old('title', $cta->title) }}" placeholder="e.g. Ready to get started?" required>
                    </div>

                    <div class="form-group mb-4">
                        <label class="form-label">{{ __('super.cta.field_subtitle') }}</label>
                        <textarea name="subtitle" class="form-control" rows="2" placeholder="e.g. Create your workspace in under a minute.">{{ old('subtitle', $cta->subtitle) }}</textarea>
                    </div>

                    <div class="form-row mb-4">
                        <div class="form-group">
                            <label class="form-label">{{ __('super.cta.btn_text') }}</label>
                            <input type="text" name="button_text" class="form-control" value="{{ old('button_text', $cta->button_text) }}" placeholder="e.g. Create your workspace">
                        </div>
                        <div class="form-group">
                            <label class="form-label">{{ __('super.cta.btn_url') }}</label>
                            <input type="text" name="button_url" class="form-control" value="{{ old('button_url', $cta->button_url) }}" placeholder="{{ route('central.register') }}">
                        </div>
                    </div>

                    <div class="form-group mb-4">
                        <label class="form-label">{{ __('super.cta.bg_image') }}</label>
                        <input type="file" name="background_image" class="form-control" accept="image/*">
                        @if($cta->background_image)
                            <p class="form-hint mt-1">{{ __('super.common.current') }}: <a href="{{ asset($cta->background_image) }}" target="_blank">{{ __('super.common.view_image') }}</a></p>
                        @endif
                    </div>

                    <div class="form-group mb-4">
                        <label class="form-switch-label">
                            <input type="checkbox" name="is_active" value="1" class="form-check-input" {{ old('is_active', $cta->is_active) ? 'checked' : '' }}>
                            <span>{{ __('super.cta.show_section') }}</span>
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
</div>
@endsection
