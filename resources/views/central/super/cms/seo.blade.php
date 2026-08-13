@extends('central.super.layout')

@section('title', __('super.seo.title'))

@section('content')
<div class="breadcrumb-custom">
    <a href="{{ route('super.cms.index') }}">CMS</a>
    <span class="separator"><i class="bi bi-chevron-right"></i></span>
    <span class="current">{{ __('super.seo.title') }}</span>
</div>

<div class="page-header">
    <h1>{{ __('super.seo.title') }}</h1>
    <p class="page-subtitle">{{ __('super.seo.subtitle') }}</p>
</div>

<div class="row g-4">
    <div class="col-lg-8">
        <div class="content-card">
            <div class="card-header-custom">
                <h2><i class="bi bi-search me-2 text-muted"></i>{{ __('super.seo.meta_tags') }}</h2>
            </div>
            <div class="card-body-custom">
                <form method="POST" action="{{ route('super.cms.seo.update') }}" enctype="multipart/form-data">
                    @csrf
                    @method('PUT')

                    <div class="form-group mb-4">
                        <label class="form-label">{{ __('super.seo.meta_title') }}</label>
                        <input type="text" name="meta_title" class="form-control" value="{{ old('meta_title', $seo->meta_title) }}" placeholder="Stocky SaaS — Inventory & POS" maxlength="70">
                        <p class="form-hint">{{ __('super.seo.title_hint') }}: <span id="titleCount">{{ strlen($seo->meta_title ?? '') }}</span>/70</p>
                    </div>

                    <div class="form-group mb-4">
                        <label class="form-label">{{ __('super.seo.meta_desc') }}</label>
                        <textarea name="meta_description" class="form-control" rows="3" placeholder="{{ __('super.seo.desc_placeholder') }}" maxlength="160">{{ old('meta_description', $seo->meta_description) }}</textarea>
                        <p class="form-hint">{{ __('super.seo.desc_hint') }}: <span id="descCount">{{ strlen($seo->meta_description ?? '') }}</span>/160</p>
                    </div>

                    <div class="form-group mb-4">
                        <label class="form-label">{{ __('super.seo.meta_keywords') }}</label>
                        <input type="text" name="meta_keywords" class="form-control" value="{{ old('meta_keywords', $seo->meta_keywords) }}" placeholder="inventory, pos, saas, stocky">
                        <p class="form-hint">{{ __('super.seo.keywords_hint') }}</p>
                    </div>

                    <div class="divider"></div>

                    <div class="form-row mb-4">
                        <div class="form-group">
                            <label class="form-label">{{ __('super.seo.og_image') }}</label>
                            <input type="file" name="og_image" class="form-control" accept="image/*">
                            @if($seo->og_image)
                                <p class="form-hint mt-1">{{ __('super.common.current') }}: <a href="{{ asset($seo->og_image) }}" target="_blank">{{ __('super.common.view_image') }}</a></p>
                            @endif
                            <p class="form-hint">{{ __('super.seo.og_hint') }}</p>
                        </div>
                        <div class="form-group">
                            <label class="form-label">{{ __('super.seo.favicon') }}</label>
                            <input type="file" name="favicon" class="form-control" accept="image/*">
                            @if($seo->favicon)
                                <p class="form-hint mt-1">{{ __('super.common.current') }}: <a href="{{ asset($seo->favicon) }}" target="_blank">{{ __('super.seo.view_favicon') }}</a></p>
                            @endif
                            <p class="form-hint">{{ __('super.seo.favicon_hint') }}</p>
                        </div>
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
        {{-- Preview card --}}
        <div class="content-card sticky-sidebar">
            <div class="card-header-custom">
                <h2><i class="bi bi-eye me-2 text-muted"></i>{{ __('super.seo.search_preview') }}</h2>
            </div>
            <div class="card-body-custom">
                <div class="seo-preview-box">
                    <p class="seo-preview-url" id="previewUrl">{{ url('/') }}</p>
                    <p class="seo-preview-title" id="previewTitle">{{ $seo->meta_title ?? __('super.seo.preview_title') }}</p>
                    <p class="seo-preview-desc" id="previewDesc">{{ $seo->meta_description ?? __('super.seo.preview_desc') }}</p>
                </div>
                <p class="text-muted mt-3 mb-0 fs-xs3">{{ __('super.seo.preview_note') }}</p>
            </div>
        </div>
    </div>
</div>

@push('scripts')
<script>
window.SeoData = {
    previewTitle: @json(__('super.seo.preview_title')),
    previewDesc:  @json(__('super.seo.preview_desc')),
};
</script>
<script src="{{ asset('assets_super/js/cms-seo.js') }}"></script>
@endpush
@endsection
