@extends('central.super.layout')

@section('title', __('super.landing_templates.page_title'))

@section('content')
<div class="page-header d-flex flex-wrap align-items-start justify-content-between gap-3">
    <div>
        <h1>{{ __('super.landing_templates.page_title') }}</h1>
        <p class="page-subtitle">{{ __('super.landing_templates.page_subtitle') }}</p>
    </div>
    <a href="{{ route('super.cms.index') }}" class="btn btn-outline-secondary">
        <i class="bi bi-arrow-left"></i> {{ __('super.common.back') }}
    </a>
</div>

<form method="POST" action="{{ route('super.cms.landing-pages.update') }}">
    @csrf
    @method('PUT')

    <div class="content-card mb-4">
        <div class="card-header-custom">
            <h2><i class="bi bi-layout-wtf me-2 text-muted"></i>{{ __('super.landing_templates.choose_default') }}</h2>
        </div>
        <div class="card-body-custom">
            <p class="text-muted mb-4">{{ __('super.landing_templates.help_public') }}</p>

            <div class="d-flex flex-column gap-3">
                @foreach($options as $opt)
                <div class="border rounded-3 p-3 p-md-4 {{ $current === $opt['key'] ? 'border-primary border-2' : '' }}">
                    <div class="d-flex flex-wrap align-items-start justify-content-between gap-3">
                        <div class="d-flex align-items-start gap-3 flex-grow-1 min-w-0">
                            <div class="form-check mt-1">
                                <input
                                    class="form-check-input"
                                    type="radio"
                                    name="landing_template"
                                    id="landing_tpl_{{ $opt['key'] }}"
                                    value="{{ $opt['key'] }}"
                                    @checked(old('landing_template', $current) === $opt['key'])
                                >
                            </div>
                            <label class="form-check-label flex-grow-1 cursor-pointer" for="landing_tpl_{{ $opt['key'] }}">
                                <span class="fw-700 d-block">{{ $opt['label'] }}</span>
                                <span class="text-muted small d-block mt-1">{{ $opt['description'] }}</span>
                                @if($current === $opt['key'])
                                    <span class="badge bg-primary mt-2">{{ __('super.landing_templates.badge_default') }}</span>
                                @endif
                            </label>
                        </div>
                        <div class="d-flex flex-shrink-0 gap-2">
                            <a
                                href="{{ route('central.welcome', ['__landing_preview' => $opt['key']]) }}"
                                target="_blank"
                                rel="noopener noreferrer"
                                class="btn btn-sm btn-outline-primary"
                            >
                                <i class="bi bi-box-arrow-up-right"></i> {{ __('super.common.preview') }}
                            </a>
                        </div>
                    </div>
                </div>
                @endforeach
            </div>

            @error('landing_template')
                <div class="text-danger mt-3 small">{{ $message }}</div>
            @enderror

            <p class="text-muted small mt-4 mb-0">
                <i class="bi bi-info-circle me-1"></i>{{ __('super.landing_templates.preview_hint') }}
            </p>
        </div>
    </div>

    <div class="d-flex flex-wrap gap-2">
        <button type="submit" class="btn btn-primary">
            <i class="bi bi-check-lg"></i> {{ __('super.common.save_changes') }}
        </button>
        <a href="{{ route('central.welcome', ['__landing_preview' => $current]) }}" target="_blank" rel="noopener noreferrer" class="btn btn-outline-secondary">
            <i class="bi bi-eye"></i> {{ __('super.landing_templates.open_live') }}
        </a>
    </div>
</form>
@endsection
