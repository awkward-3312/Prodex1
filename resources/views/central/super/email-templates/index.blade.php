@extends('central.super.layout')

@section('title', __('central.EmailNotificationTemplates'))

@section('content')
@php
    $totalLanguages = $languages->count() > 1 ? $languages->where('is_default', false)->count() : 0;
@endphp

<div class="page-header">
    <h1>{{ __('central.EmailNotificationTemplates') }}</h1>
    <p class="page-subtitle">{{ __('central.EmailTemplatesSubtitle') }}</p>
</div>

@if($templates->isEmpty())
    <div class="empty-state">
        <div class="empty-icon"><i class="bi bi-envelope-paper"></i></div>
        <h3>{{ __('central.NoTemplatesFound') }}</h3>
        <p class="text-muted">{{ __('central.RunSeederToCreateTemplates') }}<br><code>php artisan db:seed --class="Database\Seeders\Central\EmailTemplatesSeeder"</code></p>
    </div>
@else
    <div class="row g-4">
        @foreach($templates as $tpl)
        @php
            $color = $triggerColors[$tpl->trigger_key] ?? '#6366f1';
            $icon  = $triggerIcons[$tpl->trigger_key]  ?? 'bi-envelope';
            $label = $triggerLabels[$tpl->trigger_key]  ?? $tpl->trigger_key;
        @endphp
        <div class="col-sm-6 col-xl-6">
            <div class="content-card template-card">
                <div class="card-body-custom">
                    <div class="d-flex align-items-start gap-3 mb-3">
                        <div class="template-icon" style="background: {{ $color }}15; color: {{ $color }};">
                            <i class="bi {{ $icon }}"></i>
                        </div>
                        <div class="flex-grow-1">
                            <div class="d-flex align-items-center gap-2 mb-1">
                                <h3 class="mb-0 fw-700 fs-lg">{{ $tpl->name }}</h3>
                                @if($tpl->is_active)
                                    <span class="badge badge-active">{{ __('central.Active') }}</span>
                                @else
                                    <span class="badge badge-no">{{ __('central.Inactive') }}</span>
                                @endif
                            </div>
                            <p class="mb-0 text-muted fs-sm2">{{ __('central.Trigger') }}: <code>{{ $tpl->trigger_key }}</code></p>
                        </div>
                    </div>

                    @if($totalLanguages > 0)
                    @php
                        $translatedCount = $tpl->translations->count();
                    @endphp
                    <div class="d-flex align-items-center gap-2 mb-3 fs-xs3">
                        <i class="bi bi-translate text-muted"></i>
                        @if($translatedCount === $totalLanguages)
                            <span class="text-success-mid fw-600"><i class="bi bi-check-circle-fill me-1"></i>{{ __('central.AllTranslations', ['count' => $totalLanguages]) }}</span>
                        @elseif($translatedCount > 0)
                            <span class="color-warning fw-600">{{ __('central.PartialTranslations', ['done' => $translatedCount, 'total' => $totalLanguages]) }}</span>
                        @else
                            <span class="text-muted">{{ __('central.NoTranslations') }}</span>
                        @endif
                    </div>
                    @endif

                    <div class="template-subject mb-3">
                        <span class="text-muted fs-xs3 fw-600">{{ __('central.Subject') }}:</span>
                        <span class="fs-sm4">{{ $tpl->subject }}</span>
                    </div>

                    <div class="d-flex align-items-center gap-2 flex-wrap">
                        <a href="{{ route('super.email-templates.edit', $tpl) }}" class="btn btn-primary btn-sm">
                            <i class="bi bi-pencil-square"></i> {{ __('central.EditTemplate') }}
                        </a>
                        <a href="{{ route('super.email-templates.preview', $tpl) }}" class="btn btn-outline btn-sm" target="_blank">
                            <i class="bi bi-eye"></i> {{ __('central.Preview') }}
                        </a>
                    </div>
                </div>
            </div>
        </div>
        @endforeach
    </div>

    <div class="content-card mt-4">
        <div class="card-header-custom">
            <h2><i class="bi bi-braces me-2 text-muted"></i>{{ __('central.AvailableVariables') }}</h2>
        </div>
        <div class="card-body-custom">
            <p class="text-muted mb-3 fs-sm3">{{ __('central.AvailableVariablesDesc') }}</p>
            <div class="row g-2">
                @foreach(\App\Models\Central\EmailTemplate::AVAILABLE_VARIABLES as $var => $desc)
                <div class="col-sm-6 col-lg-4">
                    <div class="d-flex align-items-center gap-2 p-2 template-var-badge">
                        <code class="template-var-code">{{ $var }}</code>
                        <span class="text-muted template-var-desc">— {{ $desc }}</span>
                    </div>
                </div>
                @endforeach
            </div>
        </div>
    </div>
@endif

@endsection
