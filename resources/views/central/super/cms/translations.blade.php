@extends('central.super.layout')

@section('title', __('super.cms_translations.title'))

@section('content')
<div class="breadcrumb-custom">
    <a href="{{ route('super.cms.index') }}">CMS</a>
    <span class="separator"><i class="bi bi-chevron-right"></i></span>
    <span class="current">{{ __('super.sidebar.translations') }}</span>
</div>

<div class="page-header">
    <h1><i class="bi bi-translate me-2"></i>{{ __('super.cms_translations.title') }}</h1>
    <p class="page-subtitle">{{ __('super.cms_translations.subtitle') }}</p>
</div>

@if(session('success'))
    <div class="alert alert-success alert-dismissible fade show" role="alert">
        <i class="bi bi-check-circle me-2"></i>{{ session('success') }}
        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
    </div>
@endif

{{-- Language selector tabs --}}
<div class="card mb-4">
    <div class="card-body">
        <div class="d-flex align-items-center gap-2 flex-wrap">
            <span class="fw-bold text-muted me-2"><i class="bi bi-globe2 me-1"></i> {{ __('super.cms_translations.language') }}</span>
            @foreach($languages as $lang)
                @if(!$lang->is_default)
                <a href="{{ route('super.cms.translations', ['locale' => $lang->locale]) }}"
                   class="btn btn-sm {{ $locale === $lang->locale ? 'btn-primary' : 'btn-outline-secondary' }}">
                    @if($lang->flag)
                        <img src="{{ asset('flags/' . $lang->flag) }}" alt="" class="flag-icon-sm me-1">
                    @endif
                    {{ $lang->name }} ({{ strtoupper($lang->locale) }})
                </a>
                @endif
            @endforeach
        </div>
        <p class="text-muted small mt-2 mb-0">
            <i class="bi bi-info-circle me-1"></i>
            {{ __('super.cms_translations.info') }}
        </p>
    </div>
</div>

<form method="POST" action="{{ route('super.cms.translations.update') }}">
    @csrf
    @method('PUT')
    <input type="hidden" name="locale" value="{{ $locale }}">

    @foreach($sectionData as $sectionKey => $section)
        @if(!empty($section['items']))
        <div class="card mb-4">
            <div class="card-header d-flex align-items-center">
                <h5 class="mb-0"><i class="bi bi-layers me-2"></i>{{ $section['label'] }}</h5>
            </div>
            <div class="card-body">
                @foreach($section['items'] as $idx => $item)
                    @php
                        $record = $item['record'];
                        $fields = $item['fields'];
                        $currentTranslations = $record->getTranslationsForLocale($locale);
                    @endphp

                    @if($section['mode'] === 'multi' && count($section['items']) > 1)
                        <div class="border rounded p-3 mb-3 bg-light">
                            <h6 class="text-muted mb-3">
                                #{{ $idx + 1 }} — {{ $record->getOriginalAttribute($fields[0] ?? 'id') }}
                            </h6>
                    @endif

                    @foreach($fields as $field)
                        @php
                            $originalValue = $record->getOriginalAttribute($field);
                            $translatedValue = $currentTranslations[$field] ?? '';
                            $inputName = "translations[{$sectionKey}][{$record->id}][{$field}]";
                            $isLong = strlen($originalValue ?? '') > 100;
                        @endphp
                        <div class="mb-3">
                            <label class="form-label fw-semibold text-capitalize">
                                {{ str_replace('_', ' ', $field) }}
                            </label>
                            <div class="text-muted small mb-1" title="Default language value">
                                <i class="bi bi-flag me-1"></i>{{ Str::limit($originalValue, 200) ?: '(empty)' }}
                            </div>
                            @if($isLong)
                                <textarea
                                    name="{{ $inputName }}"
                                    class="form-control"
                                    rows="3"
                                    placeholder="{{ __('super.cms_translations.placeholder', ['locale' => strtoupper($locale)]) }}"
                                >{{ old("translations.{$sectionKey}.{$record->id}.{$field}", $translatedValue) }}</textarea>
                            @else
                                <input
                                    type="text"
                                    name="{{ $inputName }}"
                                    class="form-control"
                                    value="{{ old("translations.{$sectionKey}.{$record->id}.{$field}", $translatedValue) }}"
                                    placeholder="{{ __('super.cms_translations.placeholder', ['locale' => strtoupper($locale)]) }}"
                                >
                            @endif
                        </div>
                    @endforeach

                    @if($section['mode'] === 'multi' && count($section['items']) > 1)
                        </div>
                    @endif
                @endforeach
            </div>
        </div>
        @endif
    @endforeach

    <div class="d-flex justify-content-end mb-5">
        <button type="submit" class="btn btn-primary btn-lg">
            <i class="bi bi-check2-circle me-2"></i>{{ __('super.cms_translations.save', ['locale' => strtoupper($locale)]) }}
        </button>
    </div>
</form>
@endsection
