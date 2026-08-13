@extends('central.super.layout')

@section('title', __('central.EditEmailTemplate') . ': ' . $template->name)

@section('content')
@php
    $defaultLocale = \App\Models\Central\CentralLanguage::defaultLocale();
    $isTranslation = !empty($currentLocale);
    $editSubject = $isTranslation ? old('subject', $translation->subject ?? '') : old('subject', $template->subject);
    $editBody = $isTranslation ? old('body_html', $translation->body_html ?? '') : old('body_html', $template->body_html);
    $translatedLocales = $template->translations->pluck('locale')->toArray();
@endphp

<div class="breadcrumb-custom">
    <a href="{{ route('super.email-templates.index') }}">{{ __('central.EmailTemplates') }}</a>
    <span class="separator">/</span>
    <span class="current">{{ $template->name }}</span>
</div>

<div class="page-header">
    <h1>{{ $template->name }}</h1>
    <p class="page-subtitle">{{ __('central.Trigger') }}: <code>{{ $template->trigger_key }}</code></p>
</div>

{{-- Language Tabs --}}
@if($languages->count() > 1)
<div class="content-card mb-4">
    <div class="card-body-custom py-2 px-3">
        <div class="d-flex align-items-center gap-2 flex-wrap">
            <span class="text-muted me-1 fs-xs3 fw-600"><i class="bi bi-translate me-1"></i>{{ __('central.Language') }}:</span>
            <a href="{{ route('super.email-templates.edit', $template) }}"
               class="lang-tab {{ !$isTranslation ? 'active' : '' }}">
                {{ __('central.Default') }} ({{ strtoupper($defaultLocale) }})
            </a>
            @foreach($languages as $lang)
                @if($lang->locale !== $defaultLocale)
                <a href="{{ route('super.email-templates.edit', ['template' => $template->id, 'locale' => $lang->locale]) }}"
                   class="lang-tab {{ $currentLocale === $lang->locale ? 'active' : '' }}">
                    @if($lang->flag)
                        <img src="{{ asset('flags/' . $lang->flag) }}" alt="" class="lang-flag-img">
                    @endif
                    {{ $lang->name }}
                    @if(in_array($lang->locale, $translatedLocales))
                        <i class="bi bi-check-circle-fill text-success-mid badge-sm"></i>
                    @else
                        <i class="bi bi-circle text-muted badge-sm"></i>
                    @endif
                </a>
                @endif
            @endforeach
        </div>
    </div>
</div>
@endif

{{-- Translation notice --}}
@if($isTranslation)
<div class="mb-4 p-3 rounded-3 notice-translation">
    <div class="d-flex align-items-center gap-2">
        <i class="bi bi-translate text-info-mid fs-base"></i>
        <div>
            <p class="mb-0 notice-translation-title">
                {{ __('central.EditingTranslation') }}: {{ $languages->firstWhere('locale', $currentLocale)->name ?? strtoupper($currentLocale) }}
            </p>
            <p class="mb-0 text-muted fs-xs3">
                {{ __('central.TranslationFallbackNotice') }}
            </p>
        </div>
    </div>
</div>
@endif

<form method="POST" action="{{ route('super.email-templates.update', $template) }}" id="templateForm">
    @csrf
    @method('PUT')
    @if($isTranslation)
        <input type="hidden" name="locale" value="{{ $currentLocale }}">
    @endif

    <div class="row g-4">
        <div class="col-lg-8">
            {{-- Subject --}}
            <div class="content-card mb-4">
                <div class="card-header-custom">
                    <h2><i class="bi bi-chat-left-text me-2 text-muted"></i>{{ __('central.EmailSubject') }}</h2>
                </div>
                <div class="card-body-custom">
                    <div class="form-group">
                        <input
                            type="text"
                            name="subject"
                            class="form-control @error('subject') is-invalid @enderror"
                            value="{{ $editSubject }}"
                            placeholder="{{ $isTranslation ? $template->subject : __('central.EmailSubjectPlaceholder') }}"
                        >
                        @error('subject')
                            <div class="invalid-feedback">{{ $message }}</div>
                        @enderror
                        @if($isTranslation)
                            <p class="form-hint mt-1">{{ __('central.DefaultLabel') }}: <em>{{ $template->subject }}</em></p>
                        @else
                            <p class="form-hint mt-1">{{ __('central.SubjectVariableHint') }}</p>
                        @endif
                    </div>
                </div>
            </div>

            {{-- HTML Body --}}
            <div class="content-card mb-4">
                <div class="card-header-custom d-flex align-items-center justify-content-between">
                    <h2><i class="bi bi-code-slash me-2 text-muted"></i>{{ __('central.EmailBodyHtml') }}</h2>
                    <div class="d-flex gap-2">
                        @if($isTranslation && !$translation)
                            <button type="button" class="btn btn-outline btn-sm" id="copyDefaultBtn" title="{{ __('central.CopyDefaultTooltip') }}">
                                <i class="bi bi-clipboard me-1"></i> {{ __('central.CopyDefault') }}
                            </button>
                        @endif
                        <button type="button" class="btn btn-outline btn-sm" id="toggleEditor">
                            <i class="bi bi-code-square"></i> <span id="toggleLabel">{{ __('central.VisualEditor') }}</span>
                        </button>
                    </div>
                </div>
                <div class="card-body-custom p-0">
                    <div id="visualEditor">
                        <div id="richEditor"></div>
                    </div>
                    <div id="codeEditor">
                        <textarea
                            name="body_html"
                            id="bodyHtml"
                            class="form-control code-textarea code-textarea-clean @error('body_html') is-invalid @enderror"
                            rows="22"
                        >{{ $editBody }}</textarea>
                    </div>
                    @error('body_html')
                        <div class="text-danger px-3 pb-3 fs-sm2">{{ $message }}</div>
                    @enderror
                </div>
            </div>

            {{-- Actions --}}
            <div class="content-card mb-4">
                <div class="card-body-custom">
                    <div class="d-flex align-items-center justify-content-between flex-wrap gap-3">
                        @if(!$isTranslation)
                            <label class="form-switch-label mb-0">
                                <input type="checkbox" name="is_active" value="1" class="form-check-input" {{ old('is_active', $template->is_active) ? 'checked' : '' }}>
                                <span class="fw-600">{{ $template->is_active ? __('central.Active') : __('central.Inactive') }}</span>
                            </label>
                        @else
                            <div class="d-flex align-items-center gap-2">
                                @if($translation)
                                    <span class="badge badge-translation-exists"><i class="bi bi-check-circle me-1"></i>{{ __('central.TranslationExists') }}</span>
                                @else
                                    <span class="badge badge-translation-new"><i class="bi bi-exclamation-circle me-1"></i>{{ __('central.NewTranslation') }}</span>
                                @endif
                            </div>
                        @endif
                        <div class="d-flex gap-2">
                            @if($isTranslation && $translation)
                                <button type="button" class="btn btn-outline btn-remove-translation" id="deleteTranslationBtn">
                                    <i class="bi bi-trash"></i> {{ __('central.RemoveTranslation') }}
                                </button>
                            @endif
                            <a href="{{ route('super.email-templates.preview', ['template' => $template->id, 'locale' => $currentLocale]) }}" class="btn btn-outline" target="_blank">
                                <i class="bi bi-eye"></i> {{ __('central.Preview') }}
                            </a>
                            <button type="submit" class="btn btn-primary">
                                <i class="bi bi-check-lg"></i> {{ $isTranslation ? __('central.SaveTranslation') : __('central.SaveTemplate') }}
                            </button>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        {{-- Sidebar --}}
        <div class="col-lg-4">
            {{-- Variables reference --}}
            <div class="content-card mb-4 sticky-sidebar">
                <div class="card-header-custom">
                    <h2><i class="bi bi-braces me-2 text-muted"></i>{{ __('central.Variables') }}</h2>
                </div>
                <div class="card-body-custom">
                    <p class="text-muted mb-3 fs-sm">{{ __('central.VariablesDesc') }}</p>
                    <div class="d-flex flex-column gap-2">
                        @foreach($variables as $var => $desc)
                        <button type="button" class="var-chip" data-copy-var="{{ $var }}">
                            <code>{{ $var }}</code>
                            <span class="text-muted fs-xs">{{ $desc }}</span>
                        </button>
                        @endforeach
                    </div>

                    <div class="divider"></div>

                    {{-- Translation status --}}
                    @if($languages->count() > 1)
                    <h3 class="fw-700 mb-2 fs-md">{{ __('central.TranslationStatus') }}</h3>
                    <div class="d-flex flex-column gap-1 mb-3">
                        @foreach($languages as $lang)
                            @if($lang->locale !== $defaultLocale)
                            <div class="d-flex align-items-center justify-content-between fs-sm2">
                                <span>{{ $lang->name }}</span>
                                @if(in_array($lang->locale, $translatedLocales))
                                    <span class="text-success-mid fw-600"><i class="bi bi-check-circle-fill"></i> {{ __('central.Done') }}</span>
                                @else
                                    <span class="text-muted"><i class="bi bi-circle"></i> {{ __('central.Missing') }}</span>
                                @endif
                            </div>
                            @endif
                        @endforeach
                    </div>
                    <div class="divider"></div>
                    @endif

                    {{-- Send test email --}}
                    <h3 class="fw-700 mb-2 fs-md">{{ __('central.SendTestEmail') }}</h3>
                    <p class="text-muted mb-2 fs-xs3">{{ __('central.SendTestEmailDesc') }}</p>
                </div>
            </div>

            {{-- Test email form (outside the main form) --}}
        </div>
    </div>
</form>

@if($isTranslation && $translation)
<form method="POST" action="{{ route('super.email-templates.translation.destroy', ['template' => $template->id, 'locale' => $currentLocale]) }}" id="deleteTranslationForm">
    @csrf
    @method('DELETE')
</form>
@endif

{{-- Separate test-email form --}}
<div class="col-lg-4 offset-lg-8 mt-n1">
    <div class="content-card">
        <div class="card-body-custom">
            <form method="POST" action="{{ route('super.email-templates.send-test', $template) }}">
                @csrf
                @if($isTranslation)
                    <input type="hidden" name="locale" value="{{ $currentLocale }}">
                @endif
                <div class="form-group mb-2">
                    <input
                        type="email"
                        name="test_email"
                        class="form-control @error('test_email') is-invalid @enderror"
                        value="{{ old('test_email') }}"
                        placeholder="recipient@example.com"
                    >
                    @error('test_email')
                        <div class="invalid-feedback">{{ $message }}</div>
                    @enderror
                </div>
                <button type="submit" class="btn btn-outline btn-sm w-100">
                    <i class="bi bi-send"></i> {{ $isTranslation ? __('central.SendTestLocale', ['locale' => strtoupper($currentLocale)]) : __('central.SendTest') }}
                </button>
            </form>
        </div>
    </div>
</div>

@push('scripts')
<script>
window.EmailTemplateData = {
    defaultBody:    @json($template->body_html),
    defaultSubject: @json($template->subject),
    trans: {
        visualEditor:          @json(__('central.VisualEditor')),
        sourceCode:            @json(__('central.SourceCode')),
        active:                @json(__('central.Active')),
        inactive:              @json(__('central.Inactive')),
        removeTranslationTitle: @json(__('central.RemoveTranslationTitle')),
        removeTranslationText:  @json(__('central.RemoveTranslationText')),
        yesRemove:             @json(__('central.YesRemove')),
        cancel:                @json(__('central.Cancel')),
    },
};
</script>
<script src="{{ asset('assets_super/js/email-template-edit.js') }}"></script>
@endpush

@endsection
