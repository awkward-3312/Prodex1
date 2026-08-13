@extends('central.super.layout')

@section('title', __('super.general_settings.title'))

@push('styles')
<link href="{{ asset('assets_super/css/settings-general.css') }}?v={{ time() }}" rel="stylesheet">
@endpush

@section('content')
<div class="page-header">
    <h1>{{ __('super.general_settings.title') }}</h1>
    <p class="page-subtitle">{{ __('super.general_settings.subtitle') }}</p>
</div>

<form method="POST" action="{{ route('super.settings.general.update') }}" enctype="multipart/form-data" id="generalSettingsForm">
    @csrf
    @method('PUT')

    <div class="settings-shell">
        {{-- ── Side navigation ───────────────────────────────────────── --}}
        <aside class="settings-side" aria-label="{{ __('super.general_settings.title') }}">
            <nav class="settings-nav" role="tablist" aria-orientation="vertical">
                <button type="button" class="settings-nav-item is-active" role="tab" data-tab="general" aria-selected="true" aria-controls="panel-general">
                    <i class="bi bi-sliders"></i>
                    <span>{{ __('super.general_settings.tab_general') }}</span>
                </button>
                <button type="button" class="settings-nav-item" role="tab" data-tab="branding" aria-selected="false" aria-controls="panel-branding">
                    <i class="bi bi-palette"></i>
                    <span>{{ __('super.general_settings.tab_branding') }}</span>
                </button>
                <button type="button" class="settings-nav-item" role="tab" data-tab="appearance" aria-selected="false" aria-controls="panel-appearance">
                    <i class="bi bi-brush"></i>
                    <span>{{ __('super.general_settings.tab_appearance') }}</span>
                </button>
                <button type="button" class="settings-nav-item" role="tab" data-tab="company" aria-selected="false" aria-controls="panel-company">
                    <i class="bi bi-building"></i>
                    <span>{{ __('super.general_settings.tab_company') }}</span>
                </button>
                <button type="button" class="settings-nav-item" role="tab" data-tab="tenant" aria-selected="false" aria-controls="panel-tenant">
                    <i class="bi bi-buildings"></i>
                    <span>{{ __('super.general_settings.tab_tenant') }}</span>
                </button>
                <button type="button" class="settings-nav-item" role="tab" data-tab="reminders" aria-selected="false" aria-controls="panel-reminders">
                    <i class="bi bi-alarm"></i>
                    <span>{{ __('super.general_settings.tab_reminders') }}</span>
                </button>
                <button type="button" class="settings-nav-item" role="tab" data-tab="landing" aria-selected="false" aria-controls="panel-landing">
                    <i class="bi bi-globe"></i>
                    <span>{{ __('super.general_settings.tab_landing') }}</span>
                </button>
                <button type="button" class="settings-nav-item" role="tab" data-tab="payments" aria-selected="false" aria-controls="panel-payments">
                    <i class="bi bi-bank"></i>
                    <span>{{ __('super.general_settings.tab_payments') }}</span>
                </button>
                <button type="button" class="settings-nav-item" role="tab" data-tab="backup" aria-selected="false" aria-controls="panel-backup">
                    <i class="bi bi-shield-lock"></i>
                    <span>{{ __('super.general_settings.tab_backup') }}</span>
                </button>
                <button type="button" class="settings-nav-item" role="tab" data-tab="system" aria-selected="false" aria-controls="panel-system">
                    <i class="bi bi-hdd-rack"></i>
                    <span>{{ __('super.general_settings.tab_system') }}</span>
                </button>
                <button type="button" class="settings-nav-item" role="tab" data-tab="update" aria-selected="false" aria-controls="panel-update">
                    <i class="bi bi-cloud-arrow-down"></i>
                    <span>{{ __('super.general_settings.tab_update') }}</span>
                </button>
            </nav>
        </aside>

        {{-- ── Main content ──────────────────────────────────────────── --}}
        <div class="settings-main">
            <div class="settings-actionbar" id="settingsActionbar">
                <div class="settings-actionbar-meta">
                    <h2 class="settings-actionbar-title" id="settingsActionTitle">{{ __('super.general_settings.tab_general') }}</h2>
                    <p class="settings-actionbar-sub" id="settingsActionSub">{{ __('super.general_settings.tab_general_desc') }}</p>
                </div>
                <div class="settings-actionbar-actions">
                    <button type="submit" class="btn btn-primary">
                        <i class="bi bi-check-lg"></i> {{ __('super.general_settings.save_settings') }}
                    </button>
                </div>
            </div>

            {{-- ── Panel: General ─────────────────────────────────── --}}
            <section class="settings-panel is-active" id="panel-general" role="tabpanel" data-panel="general"
                     data-title="{{ __('super.general_settings.tab_general') }}"
                     data-desc="{{ __('super.general_settings.tab_general_desc') }}">
                <div class="form-group mb-4">
                    <label class="form-label">{{ __('super.general_settings.app_name') }} <span class="text-danger">*</span></label>
                    <input
                        type="text"
                        name="app_name"
                        class="form-control @error('app_name') is-invalid @enderror"
                        value="{{ old('app_name', $setting->app_name) }}"
                        placeholder="e.g. Stocky"
                        required
                    >
                    @error('app_name')
                        <div class="invalid-feedback">{{ $message }}</div>
                    @enderror
                </div>

                <div class="row g-3">
                    <div class="col-md-6">
                        <div class="form-group">
                            <label class="form-label">{{ __('super.general_settings.currency_code') }} <span class="text-danger">*</span></label>
                            <input
                                type="text"
                                name="currency_code"
                                class="form-control text-upper-currency @error('currency_code') is-invalid @enderror"
                                value="{{ old('currency_code', $setting->currency_code ?? 'USD') }}"
                                placeholder="e.g. USD, NGN, EUR"
                                maxlength="3"
                                required
                            >
                            <p class="form-hint mt-1">{{ __('super.general_settings.currency_code_hint') }}</p>
                            @error('currency_code')
                                <div class="invalid-feedback">{{ $message }}</div>
                            @enderror
                        </div>
                    </div>
                    <div class="col-md-6">
                        <div class="form-group">
                            <label class="form-label">{{ __('super.general_settings.currency_symbol') }} <span class="text-danger">*</span></label>
                            <input
                                type="text"
                                name="currency_symbol"
                                class="form-control max-w-120 @error('currency_symbol') is-invalid @enderror"
                                value="{{ old('currency_symbol', $setting->currency_symbol ?? '$') }}"
                                placeholder="e.g. $, €, ₦, £"
                                maxlength="5"
                                required
                            >
                            <p class="form-hint mt-1">{{ __('super.general_settings.currency_symbol_hint') }}</p>
                            @error('currency_symbol')
                                <div class="invalid-feedback">{{ $message }}</div>
                            @enderror
                        </div>
                    </div>
                </div>

                <div class="form-group mt-4">
                    <label class="form-label">{{ __('super.general_settings.default_language') }}</label>
                    <select
                        name="default_language"
                        class="form-select max-w-360 @error('default_language') is-invalid @enderror"
                    >
                        @foreach($languages as $language)
                            <option
                                value="{{ $language->locale }}"
                                {{ old('default_language', $defaultLocale) === $language->locale ? 'selected' : '' }}
                            >
                                {{ $language->name }} ({{ strtoupper($language->locale) }})
                            </option>
                        @endforeach
                    </select>
                    <p class="form-hint mt-1">{{ __('super.general_settings.default_language_hint') }}</p>
                    @error('default_language')
                        <div class="invalid-feedback">{{ $message }}</div>
                    @enderror
                </div>

                {{-- Enable / disable languages shown in the language selector --}}
                <div class="form-group mt-4">
                    <label class="form-label">{{ __('super.general_settings.manage_languages') }}</label>
                    <p class="form-hint mb-2">{{ __('super.general_settings.manage_languages_hint') }}</p>
                    @php $oldActive = old('languages_active'); @endphp
                    <div class="d-flex flex-wrap gap-3">
                        @foreach($allLanguages as $language)
                            @php
                                $isDefault = $language->locale === $defaultLocale;
                                $checked = $oldActive !== null
                                    ? in_array($language->locale, $oldActive, true)
                                    : $language->is_active;
                            @endphp
                            <label class="form-switch-label d-flex align-items-center gap-2" @if($isDefault) title="{{ __('super.general_settings.language_default_badge') }}" @endif>
                                <input type="checkbox" name="languages_active[]" value="{{ $language->locale }}"
                                       class="form-check-input"
                                       {{ $checked || $isDefault ? 'checked' : '' }}
                                       {{ $isDefault ? 'disabled' : '' }}>
                                @if($language->flag)
                                    <img src="{{ asset('flags/' . $language->flag) }}" alt="" width="18" height="13" style="border-radius:2px;object-fit:cover;">
                                @endif
                                <span>{{ $language->name }} ({{ strtoupper($language->locale) }})</span>
                                @if($isDefault)
                                    <span class="badge bg-secondary">{{ __('super.general_settings.language_default_badge') }}</span>
                                @endif
                            </label>
                            @if($isDefault)
                                {{-- Default stays active even though its checkbox is disabled --}}
                                <input type="hidden" name="languages_active[]" value="{{ $language->locale }}">
                            @endif
                        @endforeach
                    </div>
                </div>
            </section>

            {{-- ── Panel: Branding ────────────────────────────────── --}}
            <section class="settings-panel" id="panel-branding" role="tabpanel" data-panel="branding"
                     data-title="{{ __('super.general_settings.tab_branding') }}"
                     data-desc="{{ __('super.general_settings.tab_branding_desc') }}">
                {{-- Logo Upload --}}
                <div class="form-group mb-4">
                    <label class="form-label">{{ __('super.general_settings.logo') }}</label>
                    <p class="form-hint mb-2">{{ __('super.general_settings.logo_hint') }}</p>
                    <div class="upload-area" id="logoUploadArea">
                        <div class="upload-preview" id="logoPreview" style="{{ $setting->getLogoUrl() ? '' : 'display:none;' }}">
                            <img src="{{ $setting->getLogoUrl() }}" alt="{{ __('super.general_settings.logo') }}" id="logoPreviewImg">
                            <div class="upload-preview-actions">
                                <label for="logoInput" class="btn btn-sm btn-outline" title="{{ __('super.general_settings.change') }}">
                                    <i class="bi bi-pencil"></i> {{ __('super.general_settings.change') }}
                                </label>
                                @if($setting->logo_path)
                                <button type="button" class="btn btn-sm btn-outline-danger" id="removeLogoBtn" title="{{ __('super.general_settings.remove') }}">
                                    <i class="bi bi-trash"></i> {{ __('super.general_settings.remove') }}
                                </button>
                                @endif
                            </div>
                        </div>
                        <div class="upload-placeholder" id="logoPlaceholder" style="{{ $setting->getLogoUrl() ? 'display:none;' : '' }}">
                            <i class="bi bi-image upload-icon-lg"></i>
                            <p class="mb-1 fw-600 fs-sm4">{{ __('super.general_settings.upload_logo') }}</p>
                            <p class="text-muted mb-0 fs-xs3">{{ __('super.general_settings.logo_formats') }}</p>
                        </div>
                        <input type="file" name="logo" id="logoInput" accept="image/png,image/jpeg,image/svg+xml,image/webp" class="d-none">
                    </div>
                    @error('logo')
                        <div class="text-danger mt-1 fs-sm2">{{ $message }}</div>
                    @enderror
                </div>

                {{-- Favicon Upload --}}
                <div class="form-group">
                    <label class="form-label">{{ __('super.general_settings.favicon') }}</label>
                    <p class="form-hint mb-2">{{ __('super.general_settings.favicon_hint') }}</p>
                    <div class="upload-area upload-area-sm" id="faviconUploadArea">
                        <div class="upload-preview upload-preview-sm" id="faviconPreview" style="{{ $setting->getFaviconUrl() ? '' : 'display:none;' }}">
                            <img src="{{ $setting->getFaviconUrl() }}" alt="{{ __('super.general_settings.favicon') }}" id="faviconPreviewImg">
                            <div class="upload-preview-actions">
                                <label for="faviconInput" class="btn btn-sm btn-outline" title="{{ __('super.general_settings.change') }}">
                                    <i class="bi bi-pencil"></i> {{ __('super.general_settings.change') }}
                                </label>
                                @if($setting->favicon_path)
                                <button type="button" class="btn btn-sm btn-outline-danger" id="removeFaviconBtn" title="{{ __('super.general_settings.remove') }}">
                                    <i class="bi bi-trash"></i> {{ __('super.general_settings.remove') }}
                                </button>
                                @endif
                            </div>
                        </div>
                        <div class="upload-placeholder" id="faviconPlaceholder" style="{{ $setting->getFaviconUrl() ? 'display:none;' : '' }}">
                            <i class="bi bi-app upload-icon-md"></i>
                            <p class="mb-1 fw-600 fs-sm4">{{ __('super.general_settings.upload_favicon') }}</p>
                            <p class="text-muted mb-0 fs-xs3">{{ __('super.general_settings.favicon_formats') }}</p>
                        </div>
                        <input type="file" name="favicon" id="faviconInput" accept="image/png,image/x-icon,image/jpeg,image/svg+xml" class="d-none">
                    </div>
                    @error('favicon')
                        <div class="text-danger mt-1 fs-sm2">{{ $message }}</div>
                    @enderror
                </div>
            </section>

            {{-- ── Panel: Appearance ──────────────────────────────── --}}
            <section class="settings-panel" id="panel-appearance" role="tabpanel" data-panel="appearance"
                     data-title="{{ __('super.general_settings.tab_appearance') }}"
                     data-desc="{{ __('super.general_settings.tab_appearance_desc') }}">
                <div class="form-group">
                    <label class="form-switch-label d-flex align-items-center gap-2">
                        <input type="checkbox" name="show_site_name" value="1" class="form-check-input" {{ old('show_site_name', $setting->show_site_name ?? true) ? 'checked' : '' }}>
                        <span><i class="bi bi-type me-1"></i>{{ __('super.general_settings.show_site_name') }}</span>
                    </label>
                    <p class="form-hint mt-1">{{ __('super.general_settings.show_site_name_hint') }}</p>
                </div>

                <div class="form-group">
                    <label class="form-switch-label d-flex align-items-center gap-2">
                        <input type="checkbox" name="show_customizer_button" value="1" class="form-check-input" {{ old('show_customizer_button', $setting->show_customizer_button ?? true) ? 'checked' : '' }}>
                        <span><i class="bi bi-palette-fill me-1"></i>{{ __('super.general_settings.show_customizer') }}</span>
                    </label>
                    <p class="form-hint mt-1">{{ __('super.general_settings.show_customizer_hint') }}</p>
                </div>

                <div class="form-group">
                    <label class="form-label" for="dashboard_footer_text">
                        <i class="bi bi-layout-text-window-reverse me-1"></i>{{ __('super.general_settings.dashboard_footer_text') }}
                    </label>
                    <input
                        type="text"
                        id="dashboard_footer_text"
                        name="dashboard_footer_text"
                        class="form-control @error('dashboard_footer_text') is-invalid @enderror"
                        value="{{ old('dashboard_footer_text', $setting->dashboard_footer_text) }}"
                        maxlength="500"
                        placeholder="{{ __('super.general_settings.dashboard_footer_text_placeholder') }}"
                    >
                    @error('dashboard_footer_text')
                        <div class="invalid-feedback">{{ $message }}</div>
                    @enderror
                    <p class="form-hint mt-1">{{ __('super.general_settings.dashboard_footer_text_hint') }}</p>
                </div>

                {{-- Landing fonts (applied across all landing layouts) --}}
                @php
                    $fontOptions = \App\Models\Central\GeneralSetting::landingFontOptions();
                    // When a custom font is uploaded, offer it in both dropdowns.
                    if ($setting->landing_custom_font_path && $setting->landing_custom_font_name) {
                        $fontOptions = [$setting->landing_custom_font_name => __('super.general_settings.custom_font_prefix') . ': ' . $setting->landing_custom_font_name] + $fontOptions;
                    }
                @endphp
                <div class="form-row mt-4">
                    <div class="form-group">
                        <label class="form-label" for="landing_font">
                            <i class="bi bi-fonts me-1"></i>{{ __('super.general_settings.landing_font') }}
                        </label>
                        <select id="landing_font" name="landing_font" class="form-select @error('landing_font') is-invalid @enderror">
                            <option value="">{{ __('super.general_settings.landing_font_default') }}</option>
                            @foreach($fontOptions as $value => $label)
                                <option value="{{ $value }}" {{ old('landing_font', $setting->landing_font) === $value ? 'selected' : '' }}>{{ $label }}</option>
                            @endforeach
                        </select>
                        @error('landing_font')
                            <div class="invalid-feedback">{{ $message }}</div>
                        @enderror
                        <p class="form-hint mt-1">{{ __('super.general_settings.landing_font_hint') }}</p>
                    </div>
                    <div class="form-group">
                        <label class="form-label" for="landing_heading_font">
                            <i class="bi bi-type-h1 me-1"></i>{{ __('super.general_settings.landing_heading_font') }}
                        </label>
                        <select id="landing_heading_font" name="landing_heading_font" class="form-select @error('landing_heading_font') is-invalid @enderror">
                            <option value="">{{ __('super.general_settings.landing_heading_font_default') }}</option>
                            @foreach($fontOptions as $value => $label)
                                <option value="{{ $value }}" {{ old('landing_heading_font', $setting->landing_heading_font) === $value ? 'selected' : '' }}>{{ $label }}</option>
                            @endforeach
                        </select>
                        @error('landing_heading_font')
                            <div class="invalid-feedback">{{ $message }}</div>
                        @enderror
                        <p class="form-hint mt-1">{{ __('super.general_settings.landing_heading_font_hint') }}</p>
                    </div>
                </div>

                {{-- Upload a custom font --}}
                <div class="form-row mt-3">
                    <div class="form-group">
                        <label class="form-label" for="landing_custom_font_name">
                            <i class="bi bi-cloud-arrow-up me-1"></i>{{ __('super.general_settings.custom_font_name') }}
                        </label>
                        <input
                            type="text"
                            id="landing_custom_font_name"
                            name="landing_custom_font_name"
                            class="form-control @error('landing_custom_font_name') is-invalid @enderror"
                            value="{{ old('landing_custom_font_name', $setting->landing_custom_font_name) }}"
                            maxlength="50"
                            placeholder="{{ __('super.general_settings.custom_font_name_placeholder') }}"
                        >
                        @error('landing_custom_font_name')
                            <div class="invalid-feedback">{{ $message }}</div>
                        @enderror
                    </div>
                    <div class="form-group">
                        <label class="form-label" for="custom_font_file">{{ __('super.general_settings.custom_font_file') }}</label>
                        <input type="file" id="custom_font_file" name="custom_font_file" class="form-control @error('custom_font_file') is-invalid @enderror" accept=".woff2,.woff,.ttf,.otf">
                        @error('custom_font_file')
                            <div class="invalid-feedback">{{ $message }}</div>
                        @enderror
                        @if($setting->landing_custom_font_path)
                            <p class="form-hint mt-1">
                                {{ __('super.common.current') }}:
                                <a href="{{ asset($setting->landing_custom_font_path) }}" target="_blank">{{ $setting->landing_custom_font_name }}</a>
                            </p>
                            <label class="form-switch-label mt-1">
                                <input type="checkbox" name="remove_custom_font" value="1" class="form-check-input">
                                <span class="text-danger">{{ __('super.general_settings.custom_font_remove') }}</span>
                            </label>
                        @endif
                    </div>
                </div>
                <p class="form-hint mt-1">{{ __('super.general_settings.custom_font_hint') }}</p>
            </section>

            {{-- ── Panel: Company Info ────────────────────────────── --}}
            <section class="settings-panel" id="panel-company" role="tabpanel" data-panel="company"
                     data-title="{{ __('super.general_settings.tab_company') }}"
                     data-desc="{{ __('super.general_settings.tab_company_desc') }}">
                <div class="row g-3 mb-4">
                    <div class="col-md-6">
                        <div class="form-group">
                            <label class="form-label">{{ __('super.general_settings.company_name') }}</label>
                            <input
                                type="text"
                                name="company_name"
                                class="form-control @error('company_name') is-invalid @enderror"
                                value="{{ old('company_name', $setting->company_name) }}"
                                placeholder="e.g. Acme Inc."
                            >
                            @error('company_name')
                                <div class="invalid-feedback">{{ $message }}</div>
                            @enderror
                        </div>
                    </div>
                    <div class="col-md-6">
                        <div class="form-group">
                            <label class="form-label">{{ __('super.general_settings.phone_number') }}</label>
                            <input
                                type="text"
                                name="phone"
                                class="form-control @error('phone') is-invalid @enderror"
                                value="{{ old('phone', $setting->phone) }}"
                                placeholder="e.g. +1 (555) 123-4567"
                            >
                            @error('phone')
                                <div class="invalid-feedback">{{ $message }}</div>
                            @enderror
                        </div>
                    </div>
                </div>

                <div class="row g-3 mb-4">
                    <div class="col-md-6">
                        <div class="form-group">
                            <label class="form-label">{{ __('super.general_settings.email') }}</label>
                            <input
                                type="email"
                                name="email"
                                class="form-control @error('email') is-invalid @enderror"
                                value="{{ old('email', $setting->email) }}"
                                placeholder="e.g. info@company.com"
                            >
                            @error('email')
                                <div class="invalid-feedback">{{ $message }}</div>
                            @enderror
                        </div>
                    </div>
                    <div class="col-md-6">
                        <div class="form-group">
                            <label class="form-label">{{ __('super.general_settings.website_url') }}</label>
                            <input
                                type="url"
                                name="website"
                                class="form-control @error('website') is-invalid @enderror"
                                value="{{ old('website', $setting->website) }}"
                                placeholder="e.g. https://company.com"
                            >
                            @error('website')
                                <div class="invalid-feedback">{{ $message }}</div>
                            @enderror
                        </div>
                    </div>
                </div>

                <div class="form-group">
                    <label class="form-label">{{ __('super.general_settings.address') }}</label>
                    <input
                        type="text"
                        name="address"
                        class="form-control @error('address') is-invalid @enderror"
                        value="{{ old('address', $setting->address) }}"
                        placeholder="e.g. 123 Main Street, New York, NY 10001"
                    >
                    @error('address')
                        <div class="invalid-feedback">{{ $message }}</div>
                    @enderror
                </div>
            </section>

            {{-- ── Panel: Tenant Settings ─────────────────────────── --}}
            <section class="settings-panel" id="panel-tenant" role="tabpanel" data-panel="tenant"
                     data-title="{{ __('super.general_settings.tab_tenant') }}"
                     data-desc="{{ __('super.general_settings.tab_tenant_desc') }}">

                <div class="settings-callout mb-4">
                    <div class="settings-callout-icon">
                        <i class="bi bi-info-circle"></i>
                    </div>
                    <div class="settings-callout-body">
                        <h3 class="settings-callout-title">{{ __('super.general_settings.tenant_intro_title') }}</h3>
                        <p class="settings-callout-text">{{ __('super.general_settings.tenant_intro_text') }}</p>
                    </div>
                </div>

                {{-- Tenant Application Name --}}
                <div class="form-group mb-4">
                    <label class="form-label">{{ __('super.general_settings.tenant_app_name') }}</label>
                    <input
                        type="text"
                        name="tenant_app_name"
                        class="form-control @error('tenant_app_name') is-invalid @enderror"
                        value="{{ old('tenant_app_name', $setting->tenant_app_name) }}"
                        placeholder="e.g. Acme Inventory"
                        maxlength="255"
                    >
                    <p class="form-hint mt-1">{{ __('super.general_settings.tenant_app_name_hint') }}</p>
                    @error('tenant_app_name')
                        <div class="invalid-feedback">{{ $message }}</div>
                    @enderror
                </div>

                {{-- Tenant Logo --}}
                <div class="form-group mb-4">
                    <label class="form-label">{{ __('super.general_settings.tenant_logo') }}</label>
                    <p class="form-hint mb-2">{{ __('super.general_settings.tenant_logo_hint') }}</p>
                    <div class="upload-area" id="tenantLogoUploadArea">
                        <div class="upload-preview" id="tenantLogoPreview" style="{{ $setting->getTenantLogoUrl() ? '' : 'display:none;' }}">
                            <img src="{{ $setting->getTenantLogoUrl() }}" alt="{{ __('super.general_settings.tenant_logo') }}" id="tenantLogoPreviewImg">
                            <div class="upload-preview-actions">
                                <label for="tenantLogoInput" class="btn btn-sm btn-outline" title="{{ __('super.general_settings.change') }}">
                                    <i class="bi bi-pencil"></i> {{ __('super.general_settings.change') }}
                                </label>
                                @if($setting->tenant_logo_path)
                                <button type="button" class="btn btn-sm btn-outline-danger" id="removeTenantLogoBtn" title="{{ __('super.general_settings.remove') }}">
                                    <i class="bi bi-trash"></i> {{ __('super.general_settings.remove') }}
                                </button>
                                @endif
                            </div>
                        </div>
                        <div class="upload-placeholder" id="tenantLogoPlaceholder" style="{{ $setting->getTenantLogoUrl() ? 'display:none;' : '' }}">
                            <i class="bi bi-image upload-icon-lg"></i>
                            <p class="mb-1 fw-600 fs-sm4">{{ __('super.general_settings.upload_tenant_logo') }}</p>
                            <p class="text-muted mb-0 fs-xs3">{{ __('super.general_settings.logo_formats') }}</p>
                        </div>
                        <input type="file" name="tenant_logo" id="tenantLogoInput" accept="image/png,image/jpeg,image/svg+xml,image/webp" class="d-none">
                    </div>
                    @error('tenant_logo')
                        <div class="text-danger mt-1 fs-sm2">{{ $message }}</div>
                    @enderror
                </div>

                {{-- Tenant Favicon --}}
                <div class="form-group mb-4">
                    <label class="form-label">{{ __('super.general_settings.tenant_favicon') }}</label>
                    <p class="form-hint mb-2">{{ __('super.general_settings.tenant_favicon_hint') }}</p>
                    <div class="upload-area upload-area-sm" id="tenantFaviconUploadArea">
                        <div class="upload-preview upload-preview-sm" id="tenantFaviconPreview" style="{{ $setting->getTenantFaviconUrl() ? '' : 'display:none;' }}">
                            <img src="{{ $setting->getTenantFaviconUrl() }}" alt="{{ __('super.general_settings.tenant_favicon') }}" id="tenantFaviconPreviewImg">
                            <div class="upload-preview-actions">
                                <label for="tenantFaviconInput" class="btn btn-sm btn-outline" title="{{ __('super.general_settings.change') }}">
                                    <i class="bi bi-pencil"></i> {{ __('super.general_settings.change') }}
                                </label>
                                @if($setting->tenant_favicon_path)
                                <button type="button" class="btn btn-sm btn-outline-danger" id="removeTenantFaviconBtn" title="{{ __('super.general_settings.remove') }}">
                                    <i class="bi bi-trash"></i> {{ __('super.general_settings.remove') }}
                                </button>
                                @endif
                            </div>
                        </div>
                        <div class="upload-placeholder" id="tenantFaviconPlaceholder" style="{{ $setting->getTenantFaviconUrl() ? 'display:none;' : '' }}">
                            <i class="bi bi-app upload-icon-md"></i>
                            <p class="mb-1 fw-600 fs-sm4">{{ __('super.general_settings.upload_tenant_favicon') }}</p>
                            <p class="text-muted mb-0 fs-xs3">{{ __('super.general_settings.favicon_formats') }}</p>
                        </div>
                        <input type="file" name="tenant_favicon" id="tenantFaviconInput" accept="image/png,image/x-icon,image/jpeg,image/svg+xml" class="d-none">
                    </div>
                    @error('tenant_favicon')
                        <div class="text-danger mt-1 fs-sm2">{{ $message }}</div>
                    @enderror
                </div>

                <hr class="settings-divider">

                {{-- Company info --}}
                <div class="row g-3 mb-4">
                    <div class="col-md-6">
                        <div class="form-group">
                            <label class="form-label">{{ __('super.general_settings.tenant_company_name') }}</label>
                            <input
                                type="text"
                                name="tenant_company_name"
                                class="form-control @error('tenant_company_name') is-invalid @enderror"
                                value="{{ old('tenant_company_name', $setting->tenant_company_name) }}"
                                placeholder="e.g. Acme Inc."
                            >
                            <p class="form-hint mt-1">{{ __('super.general_settings.tenant_company_name_hint') }}</p>
                            @error('tenant_company_name')
                                <div class="invalid-feedback">{{ $message }}</div>
                            @enderror
                        </div>
                    </div>
                    <div class="col-md-6">
                        <div class="form-group">
                            <label class="form-label">{{ __('super.general_settings.tenant_email') }}</label>
                            <input
                                type="email"
                                name="tenant_email"
                                class="form-control @error('tenant_email') is-invalid @enderror"
                                value="{{ old('tenant_email', $setting->tenant_email) }}"
                                placeholder="e.g. admin@company.com"
                            >
                            <p class="form-hint mt-1">{{ __('super.general_settings.tenant_email_hint') }}</p>
                            @error('tenant_email')
                                <div class="invalid-feedback">{{ $message }}</div>
                            @enderror
                        </div>
                    </div>
                </div>

                <div class="row g-3 mb-4">
                    <div class="col-md-6">
                        <div class="form-group">
                            <label class="form-label">{{ __('super.general_settings.tenant_phone') }}</label>
                            <input
                                type="text"
                                name="tenant_phone"
                                class="form-control @error('tenant_phone') is-invalid @enderror"
                                value="{{ old('tenant_phone', $setting->tenant_phone) }}"
                                placeholder="e.g. +1 (555) 123-4567"
                            >
                            @error('tenant_phone')
                                <div class="invalid-feedback">{{ $message }}</div>
                            @enderror
                        </div>
                    </div>
                    <div class="col-md-6">
                        <div class="form-group">
                            <label class="form-label">{{ __('super.general_settings.tenant_address') }}</label>
                            <input
                                type="text"
                                name="tenant_address"
                                class="form-control @error('tenant_address') is-invalid @enderror"
                                value="{{ old('tenant_address', $setting->tenant_address) }}"
                                placeholder="e.g. 123 Main Street, New York"
                            >
                            @error('tenant_address')
                                <div class="invalid-feedback">{{ $message }}</div>
                            @enderror
                        </div>
                    </div>
                </div>

                <hr class="settings-divider">

                {{-- Currency & language --}}
                <div class="row g-3 mb-4">
                    <div class="col-md-4">
                        <div class="form-group">
                            <label class="form-label">{{ __('super.general_settings.tenant_currency_code') }}</label>
                            <input
                                type="text"
                                name="tenant_currency_code"
                                class="form-control text-upper-currency @error('tenant_currency_code') is-invalid @enderror"
                                value="{{ old('tenant_currency_code', $setting->tenant_currency_code) }}"
                                placeholder="e.g. USD"
                                maxlength="3"
                            >
                            <p class="form-hint mt-1">{{ __('super.general_settings.tenant_currency_code_hint') }}</p>
                            @error('tenant_currency_code')
                                <div class="invalid-feedback">{{ $message }}</div>
                            @enderror
                        </div>
                    </div>
                    <div class="col-md-4">
                        <div class="form-group">
                            <label class="form-label">{{ __('super.general_settings.tenant_currency_symbol') }}</label>
                            <input
                                type="text"
                                name="tenant_currency_symbol"
                                class="form-control @error('tenant_currency_symbol') is-invalid @enderror"
                                value="{{ old('tenant_currency_symbol', $setting->tenant_currency_symbol) }}"
                                placeholder="e.g. $, €, ₦"
                                maxlength="5"
                            >
                            @error('tenant_currency_symbol')
                                <div class="invalid-feedback">{{ $message }}</div>
                            @enderror
                        </div>
                    </div>
                    <div class="col-md-4">
                        <div class="form-group">
                            <label class="form-label">{{ __('super.general_settings.tenant_default_language') }}</label>
                            <select
                                name="tenant_default_language"
                                class="form-select @error('tenant_default_language') is-invalid @enderror"
                            >
                                <option value="">—</option>
                                @foreach($languages as $language)
                                    <option
                                        value="{{ $language->locale }}"
                                        {{ old('tenant_default_language', $setting->tenant_default_language) === $language->locale ? 'selected' : '' }}
                                    >
                                        {{ $language->name }} ({{ strtoupper($language->locale) }})
                                    </option>
                                @endforeach
                            </select>
                            <p class="form-hint mt-1">{{ __('super.general_settings.tenant_default_language_hint') }}</p>
                            @error('tenant_default_language')
                                <div class="invalid-feedback">{{ $message }}</div>
                            @enderror
                        </div>
                    </div>
                </div>

                <hr class="settings-divider">

                {{-- Footer / suffix / developed by --}}
                <div class="row g-3">
                    <div class="col-md-6">
                        <div class="form-group">
                            <label class="form-label">{{ __('super.general_settings.tenant_footer_text') }}</label>
                            <input
                                type="text"
                                name="tenant_footer_text"
                                class="form-control @error('tenant_footer_text') is-invalid @enderror"
                                value="{{ old('tenant_footer_text', $setting->tenant_footer_text) }}"
                                placeholder="e.g. Stocky - Ultimate Inventory With POS"
                            >
                            <p class="form-hint mt-1">{{ __('super.general_settings.tenant_footer_text_hint') }}</p>
                            @error('tenant_footer_text')
                                <div class="invalid-feedback">{{ $message }}</div>
                            @enderror
                        </div>
                    </div>
                    <div class="col-md-6">
                        <div class="form-group">
                            <label class="form-label">{{ __('super.general_settings.tenant_page_title_suffix') }}</label>
                            <input
                                type="text"
                                name="tenant_page_title_suffix"
                                class="form-control @error('tenant_page_title_suffix') is-invalid @enderror"
                                value="{{ old('tenant_page_title_suffix', $setting->tenant_page_title_suffix) }}"
                                placeholder="e.g. Ultimate Inventory With POS"
                            >
                            <p class="form-hint mt-1">{{ __('super.general_settings.tenant_page_title_suffix_hint') }}</p>
                            @error('tenant_page_title_suffix')
                                <div class="invalid-feedback">{{ $message }}</div>
                            @enderror
                        </div>
                    </div>
                    <div class="col-md-6">
                        <div class="form-group">
                            <label class="form-label">{{ __('super.general_settings.tenant_developed_by') }}</label>
                            <input
                                type="text"
                                name="tenant_developed_by"
                                class="form-control @error('tenant_developed_by') is-invalid @enderror"
                                value="{{ old('tenant_developed_by', $setting->tenant_developed_by) }}"
                                placeholder="e.g. Acme Inc."
                            >
                            <p class="form-hint mt-1">{{ __('super.general_settings.tenant_developed_by_hint') }}</p>
                            @error('tenant_developed_by')
                                <div class="invalid-feedback">{{ $message }}</div>
                            @enderror
                        </div>
                    </div>
                </div>

                <hr class="settings-divider">

                {{-- Reserved Subdomains --}}
                <div class="form-group">
                    <label class="form-label" for="reserved_subdomains">
                        <i class="bi bi-shield-lock me-1"></i>{{ __('super.general_settings.reserved_subdomains') }}
                    </label>
                    <p class="form-hint mb-2">{{ __('super.general_settings.reserved_subdomains_hint') }}</p>
                    @php
                        $reservedValue = old(
                            'reserved_subdomains',
                            implode("\n", $setting->reserved_subdomains ?? [])
                        );
                    @endphp
                    <textarea
                        id="reserved_subdomains"
                        name="reserved_subdomains"
                        rows="5"
                        class="form-control @error('reserved_subdomains') is-invalid @enderror"
                        placeholder="admin&#10;server&#10;webmail&#10;mail"
                        maxlength="5000"
                    >{{ $reservedValue }}</textarea>
                    <p class="form-hint mt-1">
                        <i class="bi bi-info-circle me-1"></i>{{ __('super.general_settings.reserved_subdomains_system_note') }}
                    </p>
                    @error('reserved_subdomains')
                        <div class="invalid-feedback d-block">{{ $message }}</div>
                    @enderror
                </div>

                <hr class="settings-divider">

                {{-- Demo Tenant Generator --}}
                <div class="form-group">
                    <label class="form-switch-label d-flex align-items-center gap-2">
                        <input type="checkbox" name="demo_data_enabled" value="1" class="form-check-input"
                               {{ old('demo_data_enabled', $setting->demo_data_enabled ?? false) ? 'checked' : '' }}>
                        <span><i class="bi bi-box-seam me-1"></i>{{ __('super.general_settings.demo_data_enabled') }}</span>
                    </label>
                    <p class="form-hint mt-1">{{ __('super.general_settings.demo_data_enabled_hint') }}</p>
                </div>
            </section>

            {{-- ── Panel: Subscription Reminders ──────────────────── --}}
            <section class="settings-panel" id="panel-reminders" role="tabpanel" data-panel="reminders"
                     data-title="{{ __('super.general_settings.tab_reminders') }}"
                     data-desc="{{ __('super.general_settings.tab_reminders_desc') }}">

                {{-- Master toggle: expiry reminders --}}
                <div class="form-group mb-4">
                    <label class="form-switch-label d-flex align-items-center gap-2">
                        <input type="checkbox" name="subscription_reminders_enabled" value="1" class="form-check-input"
                               {{ old('subscription_reminders_enabled', $setting->remindersEnabled()) ? 'checked' : '' }}>
                        <span><i class="bi bi-bell me-1"></i>{{ __('super.general_settings.reminders_enabled') }}</span>
                    </label>
                    <p class="form-hint mt-1">{{ __('super.general_settings.reminders_enabled_hint') }}</p>
                </div>

                {{-- Expiry reminder offsets --}}
                <div class="form-group mb-4">
                    <label class="form-label" for="subscription_reminder_offsets">
                        <i class="bi bi-calendar-event me-1"></i>{{ __('super.general_settings.reminder_offsets') }}
                    </label>
                    <p class="form-hint mb-2">{{ __('super.general_settings.reminder_offsets_hint') }}</p>
                    <input type="text" id="subscription_reminder_offsets" name="subscription_reminder_offsets"
                           class="form-control @error('subscription_reminder_offsets') is-invalid @enderror"
                           value="{{ old('subscription_reminder_offsets', implode(', ', $setting->reminderOffsets())) }}"
                           placeholder="7, 3, 1" maxlength="255">
                    @error('subscription_reminder_offsets')
                        <div class="invalid-feedback d-block">{{ $message }}</div>
                    @enderror
                </div>

                {{-- Delivery channels --}}
                <div class="form-group mb-4">
                    <label class="form-label">
                        <i class="bi bi-broadcast me-1"></i>{{ __('super.general_settings.reminder_channels') }}
                    </label>
                    <p class="form-hint mb-2">{{ __('super.general_settings.reminder_channels_hint') }}</p>
                    @php $activeChannels = old('subscription_reminder_channels', $setting->reminderChannels()); @endphp
                    <div class="d-flex flex-wrap gap-3">
                        <label class="form-switch-label d-flex align-items-center gap-2">
                            <input type="checkbox" name="subscription_reminder_channels[]" value="email" class="form-check-input"
                                   {{ in_array('email', $activeChannels) ? 'checked' : '' }}>
                            <span><i class="bi bi-envelope me-1"></i>{{ __('super.general_settings.channel_email') }}</span>
                        </label>
                        <label class="form-switch-label d-flex align-items-center gap-2">
                            <input type="checkbox" name="subscription_reminder_channels[]" value="sms" class="form-check-input"
                                   {{ in_array('sms', $activeChannels) ? 'checked' : '' }}>
                            <span><i class="bi bi-chat-dots me-1"></i>{{ __('super.general_settings.channel_sms') }}</span>
                        </label>
                        <label class="form-switch-label d-flex align-items-center gap-2">
                            <input type="checkbox" name="subscription_reminder_channels[]" value="banner" class="form-check-input"
                                   {{ in_array('banner', $activeChannels) ? 'checked' : '' }}>
                            <span><i class="bi bi-window me-1"></i>{{ __('super.general_settings.channel_banner') }}</span>
                        </label>
                    </div>
                </div>

                {{-- In-app banner threshold --}}
                <div class="form-group mb-4">
                    <label class="form-label" for="subscription_banner_threshold_days">
                        <i class="bi bi-window-dock me-1"></i>{{ __('super.general_settings.banner_threshold') }}
                    </label>
                    <p class="form-hint mb-2">{{ __('super.general_settings.banner_threshold_hint') }}</p>
                    <input type="number" id="subscription_banner_threshold_days" name="subscription_banner_threshold_days"
                           class="form-control w-auto @error('subscription_banner_threshold_days') is-invalid @enderror"
                           value="{{ old('subscription_banner_threshold_days', $setting->bannerThresholdDays()) }}"
                           min="1" max="90">
                    @error('subscription_banner_threshold_days')
                        <div class="invalid-feedback d-block">{{ $message }}</div>
                    @enderror
                </div>

                {{-- SMS configuration (only relevant when the SMS channel is on) --}}
                <div class="form-group mb-4">
                    <label class="form-label" for="sms_gateway">
                        <i class="bi bi-broadcast-pin me-1"></i>{{ __('super.general_settings.sms_gateway') }}
                    </label>
                    <p class="form-hint mb-2">{{ __('super.general_settings.sms_gateway_hint') }}</p>
                    <select id="sms_gateway" name="sms_gateway" class="form-control filter-select w-auto @error('sms_gateway') is-invalid @enderror">
                        <option value="">{{ __('super.general_settings.sms_gateway_none') }}</option>
                        @foreach(\App\Models\Central\GeneralSetting::SMS_GATEWAYS as $gw)
                            <option value="{{ $gw }}" {{ old('sms_gateway', $setting->sms_gateway) === $gw ? 'selected' : '' }}>{{ ucfirst($gw) }}</option>
                        @endforeach
                    </select>
                    @error('sms_gateway')
                        <div class="invalid-feedback d-block">{{ $message }}</div>
                    @enderror
                </div>

                <div class="form-group mb-4">
                    <label class="form-label" for="subscription_reminder_sms">
                        <i class="bi bi-chat-text me-1"></i>{{ __('super.general_settings.reminder_sms') }}
                    </label>
                    <p class="form-hint mb-2">{{ __('super.general_settings.sms_placeholders_hint') }}</p>
                    <textarea id="subscription_reminder_sms" name="subscription_reminder_sms" rows="3"
                              class="form-control @error('subscription_reminder_sms') is-invalid @enderror"
                              maxlength="480" placeholder="{{ \App\Models\Central\GeneralSetting::DEFAULT_REMINDER_SMS }}">{{ old('subscription_reminder_sms', $setting->subscription_reminder_sms) }}</textarea>
                    @error('subscription_reminder_sms')
                        <div class="invalid-feedback d-block">{{ $message }}</div>
                    @enderror
                </div>

                <div class="form-group mb-4">
                    <label class="form-label" for="trial_reminder_sms">
                        <i class="bi bi-chat-text me-1"></i>{{ __('super.general_settings.trial_sms') }}
                    </label>
                    <p class="form-hint mb-2">{{ __('super.general_settings.sms_placeholders_hint') }}</p>
                    <textarea id="trial_reminder_sms" name="trial_reminder_sms" rows="3"
                              class="form-control @error('trial_reminder_sms') is-invalid @enderror"
                              maxlength="480" placeholder="{{ \App\Models\Central\GeneralSetting::DEFAULT_TRIAL_SMS }}">{{ old('trial_reminder_sms', $setting->trial_reminder_sms) }}</textarea>
                    @error('trial_reminder_sms')
                        <div class="invalid-feedback d-block">{{ $message }}</div>
                    @enderror
                </div>

                <hr class="settings-divider">

                {{-- Trial reminders --}}
                <div class="form-group mb-4">
                    <label class="form-switch-label d-flex align-items-center gap-2">
                        <input type="checkbox" name="trial_reminders_enabled" value="1" class="form-check-input"
                               {{ old('trial_reminders_enabled', $setting->trialRemindersEnabled()) ? 'checked' : '' }}>
                        <span><i class="bi bi-hourglass-split me-1"></i>{{ __('super.general_settings.trial_reminders_enabled') }}</span>
                    </label>
                    <p class="form-hint mt-1">{{ __('super.general_settings.trial_reminders_enabled_hint') }}</p>
                </div>

                <div class="form-group mb-4">
                    <label class="form-label" for="trial_reminder_offsets">
                        <i class="bi bi-calendar-event me-1"></i>{{ __('super.general_settings.trial_reminder_offsets') }}
                    </label>
                    <p class="form-hint mb-2">{{ __('super.general_settings.trial_reminder_offsets_hint') }}</p>
                    <input type="text" id="trial_reminder_offsets" name="trial_reminder_offsets"
                           class="form-control @error('trial_reminder_offsets') is-invalid @enderror"
                           value="{{ old('trial_reminder_offsets', implode(', ', $setting->trialReminderOffsets())) }}"
                           placeholder="3, 1" maxlength="255">
                    @error('trial_reminder_offsets')
                        <div class="invalid-feedback d-block">{{ $message }}</div>
                    @enderror
                </div>

                <p class="form-hint">
                    <i class="bi bi-info-circle me-1"></i>{!! __('super.general_settings.reminder_log_note', ['url' => route('super.subscriptions.reminders')]) !!}
                </p>
            </section>

            {{-- ── Panel: Landing ─────────────────────────────────── --}}
            <section class="settings-panel" id="panel-landing" role="tabpanel" data-panel="landing"
                     data-title="{{ __('super.general_settings.tab_landing') }}"
                     data-desc="{{ __('super.general_settings.tab_landing_desc') }}">
                <div class="form-group">
                    <label class="form-switch-label d-flex align-items-center gap-2">
                        <input type="checkbox" name="show_admin_login" value="1" class="form-check-input" {{ old('show_admin_login', $footer->show_admin_login ?? false) ? 'checked' : '' }}>
                        <span><i class="bi bi-person-lock me-1"></i>{{ __('super.general_settings.show_admin_login') }}</span>
                    </label>
                    <p class="form-hint mt-1">{{ __('super.general_settings.show_admin_login_hint') }}</p>
                </div>
            </section>

            {{-- ── Panel: Payments / Bank ─────────────────────────── --}}
            <section class="settings-panel" id="panel-payments" role="tabpanel" data-panel="payments"
                     data-title="{{ __('super.general_settings.tab_payments') }}"
                     data-desc="{{ __('super.general_settings.tab_payments_desc') }}">
                @php $bank = $setting->getBankDetails(); @endphp

                <div class="row g-3 mb-4">
                    <div class="col-md-6">
                        <div class="form-group">
                            <label class="form-label">{{ __('super.general_settings.bank_name') }}</label>
                            <input type="text" name="bank_details[bank_name]" class="form-control"
                                   value="{{ old('bank_details.bank_name', $bank['bank_name'] ?? '') }}"
                                   placeholder="e.g. Chase Bank, Barclays">
                        </div>
                    </div>
                    <div class="col-md-6">
                        <div class="form-group">
                            <label class="form-label">{{ __('super.general_settings.account_holder') }}</label>
                            <input type="text" name="bank_details[account_holder]" class="form-control"
                                   value="{{ old('bank_details.account_holder', $bank['account_holder'] ?? '') }}"
                                   placeholder="e.g. Acme Inc.">
                        </div>
                    </div>
                </div>

                <div class="row g-3 mb-4">
                    <div class="col-md-6">
                        <div class="form-group">
                            <label class="form-label">{{ __('super.general_settings.account_number') }}</label>
                            <input type="text" name="bank_details[account_number]" class="form-control"
                                   value="{{ old('bank_details.account_number', $bank['account_number'] ?? '') }}"
                                   placeholder="e.g. 1234567890">
                        </div>
                    </div>
                    <div class="col-md-6">
                        <div class="form-group">
                            <label class="form-label">{{ __('super.general_settings.iban') }}</label>
                            <input type="text" name="bank_details[iban]" class="form-control"
                                   value="{{ old('bank_details.iban', $bank['iban'] ?? '') }}"
                                   placeholder="e.g. GB29 NWBK 6016 1331 9268 19">
                        </div>
                    </div>
                </div>

                <div class="row g-3 mb-4">
                    <div class="col-md-6">
                        <div class="form-group">
                            <label class="form-label">{{ __('super.general_settings.swift_code') }}</label>
                            <input type="text" name="bank_details[swift]" class="form-control"
                                   value="{{ old('bank_details.swift', $bank['swift'] ?? '') }}"
                                   placeholder="e.g. NWBKGB2L">
                        </div>
                    </div>
                    <div class="col-md-6">
                        <div class="form-group">
                            <label class="form-label">{{ __('super.general_settings.branch') }}</label>
                            <input type="text" name="bank_details[branch]" class="form-control"
                                   value="{{ old('bank_details.branch', $bank['branch'] ?? '') }}"
                                   placeholder="e.g. Main Branch, New York">
                        </div>
                    </div>
                </div>

                <div class="form-group">
                    <label class="form-label">{{ __('super.general_settings.payment_instructions') }}</label>
                    <textarea name="bank_details[instructions]" class="form-control" rows="3"
                              placeholder="e.g. Please include your company name as the payment reference. Payments are verified within 24-48 hours.">{{ old('bank_details.instructions', $bank['instructions'] ?? '') }}</textarea>
                    <p class="form-hint mt-1">{{ __('super.general_settings.payment_instructions_hint') }}</p>
                </div>
            </section>

            {{-- ── Panel: Backup ──────────────────────────────────── --}}
            <section class="settings-panel" id="panel-backup" role="tabpanel" data-panel="backup"
                     data-title="{{ __('super.general_settings.tab_backup') }}"
                     data-desc="{{ __('super.general_settings.tab_backup_desc') }}">
                <div class="backup-head d-flex flex-wrap align-items-center justify-content-between gap-2 mb-3">
                    <div>
                        <h3 class="fs-md2 fw-700 mb-1"><i class="bi bi-database-down me-1"></i>{{ __('super.general_settings.backup_title') }}</h3>
                        <p class="form-hint mb-0">{{ __('super.general_settings.backup_hint') }}</p>
                    </div>
                    <button type="button" class="btn btn-primary" id="createBackupBtn">
                        <i class="bi bi-plus-lg me-1"></i>
                        <span class="btn-label">{{ __('super.general_settings.backup_create_now') }}</span>
                    </button>
                </div>

                <div class="backup-security-note">
                    <i class="bi bi-shield-check"></i>
                    <span>{{ __('super.general_settings.backup_security_note') }}</span>
                </div>

                <div class="backup-table-wrap" id="backupTableWrap">
                    <table class="backup-table" aria-live="polite">
                        <thead>
                            <tr>
                                <th scope="col">{{ __('super.general_settings.backup_file') }}</th>
                                <th scope="col">{{ __('super.general_settings.backup_size') }}</th>
                                <th scope="col">{{ __('super.general_settings.backup_date') }}</th>
                                <th scope="col" class="text-end">{{ __('super.general_settings.backup_actions') }}</th>
                            </tr>
                        </thead>
                        <tbody id="backupTableBody">
                            @forelse($backups as $b)
                                <tr data-name="{{ $b['name'] }}">
                                    <td><code class="backup-file-name">{{ $b['name'] }}</code></td>
                                    <td>{{ $b['size'] }}</td>
                                    <td>{{ $b['date'] }}</td>
                                    <td class="text-end">
                                        <a href="{{ route('super.settings.general.backup.download', ['filename' => $b['name']]) }}"
                                           class="btn btn-sm btn-outline"
                                           title="{{ __('super.general_settings.backup_download') }}">
                                            <i class="bi bi-download"></i>
                                        </a>
                                        <button type="button" class="btn btn-sm btn-outline-danger js-delete-backup"
                                                data-name="{{ $b['name'] }}"
                                                title="{{ __('super.general_settings.backup_delete') }}">
                                            <i class="bi bi-trash"></i>
                                        </button>
                                    </td>
                                </tr>
                            @empty
                                <tr class="backup-empty-row">
                                    <td colspan="4" class="text-center text-muted py-4">
                                        <i class="bi bi-inbox d-block fs-xl mb-2"></i>
                                        {{ __('super.general_settings.backup_empty') }}
                                    </td>
                                </tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
            </section>

            {{-- ── Panel: System ──────────────────────────────────── --}}
            <section class="settings-panel" id="panel-system" role="tabpanel" data-panel="system"
                     data-title="{{ __('super.general_settings.tab_system') }}"
                     data-desc="{{ __('super.general_settings.tab_system_desc') }}">
                <div class="form-group mb-4">
                    <label class="form-label d-flex align-items-center gap-2 mb-2">
                        <i class="bi bi-hdd-rack text-muted"></i>{{ __('super.general_settings.hosting_mode') }}
                    </label>
                    <p class="form-hint mb-3">{{ __('super.general_settings.hosting_mode_hint') }}</p>
                    <div class="hosting-mode-options">
                        <label class="hosting-mode-option {{ ($setting->hosting_mode ?? 'vps') === 'vps' ? 'active' : '' }}">
                            <input type="radio" name="hosting_mode" value="vps" {{ ($setting->hosting_mode ?? 'vps') === 'vps' ? 'checked' : '' }}>
                            <div class="hosting-mode-content">
                                <div class="d-flex align-items-center gap-2 mb-1">
                                    <i class="bi bi-hdd-stack text-primary hosting-mode-icon"></i>
                                    <span class="fw-700 fs-md2">{{ __('super.general_settings.vps') }}</span>
                                </div>
                                <p class="text-muted mb-0 fs-sm">
                                    {{ __('super.general_settings.vps_desc') }}
                                </p>
                            </div>
                        </label>
                        <label class="hosting-mode-option {{ ($setting->hosting_mode ?? 'vps') === 'shared' ? 'active' : '' }}">
                            <input type="radio" name="hosting_mode" value="shared" {{ ($setting->hosting_mode ?? 'vps') === 'shared' ? 'checked' : '' }}>
                            <div class="hosting-mode-content">
                                <div class="d-flex align-items-center gap-2 mb-1">
                                    <i class="bi bi-cloud text-warning hosting-mode-icon"></i>
                                    <span class="fw-700 fs-md2">{{ __('super.general_settings.shared_hosting') }}</span>
                                </div>
                                <p class="text-muted mb-0 fs-sm">
                                    {{ __('super.general_settings.shared_hosting_desc') }}
                                </p>
                            </div>
                        </label>
                    </div>
                </div>

                <hr class="settings-divider">

                <div class="form-group mb-4">
                    <label class="form-label d-flex align-items-center gap-2 mb-2">
                        <i class="bi bi-terminal text-muted"></i>Environment (.env)
                    </label>
                    <p class="form-hint mb-3">
                        These values are written to the <code>.env</code> file. Incorrect values can take the site offline &mdash; change with care.
                    </p>

                    <div class="row g-3">
                        <div class="col-md-6">
                            <div class="form-group">
                                <label class="form-label">Select Environment</label>
                                <select
                                    name="app_env"
                                    class="form-control form-select @error('app_env') is-invalid @enderror"
                                >
                                    @php $currentEnv = old('app_env', $env['APP_ENV'] ?? 'production'); @endphp
                                    <option value="local"      {{ $currentEnv === 'local' ? 'selected' : '' }}>local</option>
                                    <option value="staging"    {{ $currentEnv === 'staging' ? 'selected' : '' }}>staging</option>
                                    <option value="production" {{ $currentEnv === 'production' ? 'selected' : '' }}>production</option>
                                </select>
                                <p class="form-hint mt-1">Use <strong>production</strong> on live sites.</p>
                                @error('app_env')
                                    <div class="invalid-feedback">{{ $message }}</div>
                                @enderror
                            </div>
                        </div>

                        <div class="col-md-6">
                            <div class="form-group">
                                <label class="form-label">App Debug Mode</label>
                                <select
                                    name="app_debug"
                                    class="form-control form-select @error('app_debug') is-invalid @enderror"
                                >
                                    @php $currentDebug = old('app_debug', $env['APP_DEBUG'] ?? 'false'); @endphp
                                    <option value="false" {{ $currentDebug === 'false' ? 'selected' : '' }}>Off (false)</option>
                                    <option value="true"  {{ $currentDebug === 'true'  ? 'selected' : '' }}>On (true)</option>
                                </select>
                                <p class="form-hint mt-1">Keep <strong>Off</strong> on production to avoid leaking stack traces.</p>
                                @error('app_debug')
                                    <div class="invalid-feedback">{{ $message }}</div>
                                @enderror
                            </div>
                        </div>

                        <div class="col-12">
                            <div class="form-group">
                                <label class="form-label">Application URL</label>
                                <input
                                    type="url"
                                    name="app_url"
                                    class="form-control @error('app_url') is-invalid @enderror"
                                    value="{{ old('app_url', $env['APP_URL'] ?? '') }}"
                                    placeholder="https://example.com"
                                >
                                <p class="form-hint mt-1">The canonical URL of your app (no trailing slash). Used for links in emails, OAuth, and tenant URLs.</p>
                                @error('app_url')
                                    <div class="invalid-feedback">{{ $message }}</div>
                                @enderror
                            </div>
                        </div>
                    </div>
                </div>

                <hr class="settings-divider">

                <div class="settings-callout">
                    <div class="settings-callout-icon">
                        <i class="bi bi-arrow-repeat"></i>
                    </div>
                    <div class="settings-callout-body">
                        <h3 class="settings-callout-title">{{ __('super.general_settings.clear_cache_title') }}</h3>
                        <p class="settings-callout-text">{{ __('super.general_settings.clear_cache_desc') }}</p>
                    </div>
                    <div class="settings-callout-action">
                        <button type="button" class="btn btn-outline-warning" id="clearCacheBtn">
                            <i class="bi bi-trash3 me-1"></i> {{ __('super.general_settings.clear_central_cache') }}
                        </button>
                    </div>
                </div>
            </section>

            {{-- ── Panel: Update App ──────────────────────────────── --}}
            <section class="settings-panel" id="panel-update" role="tabpanel" data-panel="update"
                     data-title="{{ __('super.general_settings.tab_update') }}"
                     data-desc="{{ __('super.general_settings.tab_update_desc') }}">
                <div class="update-intro">
                    <div class="update-intro-icon">
                        <i class="bi bi-cloud-arrow-down"></i>
                    </div>
                    <div>
                        <h3 class="update-intro-title">{{ __('super.general_settings.update_intro_title') }}</h3>
                        <p class="update-intro-text mb-0">{{ __('super.general_settings.update_intro_text') }}</p>
                    </div>
                </div>

                <div class="update-warning" role="alert">
                    <i class="bi bi-exclamation-triangle-fill"></i>
                    <span>{{ __('super.general_settings.update_warning_manual_changes') }}</span>
                </div>

                <ol class="update-steps">
                    <li class="update-step">
                        <div class="update-step-num">1</div>
                        <div class="update-step-body">
                            <h4 class="update-step-title">{{ __('super.general_settings.update_step1_title') }}</h4>
                            <ul class="update-step-list">
                                <li>{!! __('super.general_settings.update_step1_item1', ['path' => '<code>/super/settings/general#tab=backup</code>']) !!}</li>
                                <li>{{ __('super.general_settings.update_step1_item2') }}</li>
                                <li>{{ __('super.general_settings.update_step1_item4') }}</li>
                            </ul>
                            <div class="update-step-or">{{ __('super.general_settings.update_or') }}</div>
                            <ul class="update-step-list">
                                <li>{{ __('super.general_settings.update_step1_item5') }}</li>
                            </ul>
                        </div>
                    </li>

                    <li class="update-step">
                        <div class="update-step-num">2</div>
                        <div class="update-step-body">
                            <h4 class="update-step-title">{{ __('super.general_settings.update_step2_title') }}</h4>
                        </div>
                    </li>

                    <li class="update-step">
                        <div class="update-step-num">3</div>
                        <div class="update-step-body">
                            <h4 class="update-step-title">{{ __('super.general_settings.update_step3_title') }}</h4>
                        </div>
                    </li>

                    <li class="update-step">
                        <div class="update-step-num">4</div>
                        <div class="update-step-body">
                            <h4 class="update-step-title">{{ __('super.general_settings.update_step4_title') }}</h4>
                            <ul class="update-step-list update-step-list--files">
                                <li><code>.env</code> {{ __('super.general_settings.update_file_label') }}</li>
                                <li><code>storage</code> {{ __('super.general_settings.update_folder_label') }}</li>
                                <li><code>/public/images</code> {{ __('super.general_settings.update_folder_label') }}</li>
                            </ul>
                        </div>
                    </li>

                    <li class="update-step">
                        <div class="update-step-num">5</div>
                        <div class="update-step-body">
                            <h4 class="update-step-title">{{ __('super.general_settings.update_step5_title') }}</h4>
                            <ul class="update-step-list update-step-list--files">
                                <li><code>.env</code> {{ __('super.general_settings.update_file_label') }}</li>
                                <li><code>storage</code> {{ __('super.general_settings.update_folder_label') }}</li>
                                <li><code>/public/images</code> {{ __('super.general_settings.update_folder_label') }}</li>
                            </ul>
                        </div>
                    </li>

                    <li class="update-step">
                        <div class="update-step-num">6</div>
                        <div class="update-step-body">
                            <h4 class="update-step-title">{!! __('super.general_settings.update_step6_title', ['path' => '<code>' . rtrim(config('app.url'), '/') . '/update</code>']) !!}</h4>
                        </div>
                    </li>

                    <li class="update-step">
                        <div class="update-step-num">7</div>
                        <div class="update-step-body">
                            <h4 class="update-step-title">{{ __('super.general_settings.update_step7_title') }}</h4>
                        </div>
                    </li>

                    <li class="update-step update-step--done">
                        <div class="update-step-num"><i class="bi bi-check-lg"></i></div>
                        <div class="update-step-body">
                            <h4 class="update-step-title">{{ __('super.general_settings.update_step8_title') }}</h4>
                        </div>
                    </li>
                </ol>

                <div class="update-note">
                    <i class="bi bi-info-circle"></i>
                    <span>{{ __('super.general_settings.update_note_blank_page') }}</span>
                </div>
            </section>
        </div>
    </div>
</form>

{{-- Hidden helper forms --}}
<form method="POST" action="{{ route('super.settings.general.clear-cache') }}" id="clearCacheForm" class="d-none">
    @csrf
</form>
<form method="POST" action="{{ route('super.settings.general.remove-logo') }}" id="removeLogoForm" class="d-none">
    @csrf
    @method('DELETE')
</form>
<form method="POST" action="{{ route('super.settings.general.remove-favicon') }}" id="removeFaviconForm" class="d-none">
    @csrf
    @method('DELETE')
</form>
<form method="POST" action="{{ route('super.settings.general.remove-tenant-logo') }}" id="removeTenantLogoForm" class="d-none">
    @csrf
    @method('DELETE')
</form>
<form method="POST" action="{{ route('super.settings.general.remove-tenant-favicon') }}" id="removeTenantFaviconForm" class="d-none">
    @csrf
    @method('DELETE')
</form>

@push('scripts')
<script>
window.GeneralSettingsData = {
    removeLogoConfirm:    @json(__('super.general_settings.remove_logo')),
    removeFaviconConfirm: @json(__('super.general_settings.remove_favicon')),
    removeTenantLogoConfirm:    @json(__('super.general_settings.remove_tenant_logo')),
    removeTenantFaviconConfirm: @json(__('super.general_settings.remove_tenant_favicon')),
    backupDeleteConfirm:  @json(__('super.general_settings.backup_delete_confirm')),
    backupDeleteTitle:    @json(__('super.general_settings.backup_delete_title')),
    backupYesDelete:      @json(__('super.general_settings.backup_yes_delete')),
    backupCreating:       @json(__('super.general_settings.backup_creating')),
    backupCreated:        @json(__('super.general_settings.backup_created')),
    backupDeleted:        @json(__('super.general_settings.backup_deleted')),
    backupFailed:         @json(__('super.general_settings.backup_failed')),
    backupDownload:       @json(__('super.general_settings.backup_download')),
    backupDelete:         @json(__('super.general_settings.backup_delete')),
    backupEmpty:          @json(__('super.general_settings.backup_empty')),
    cancelText:           @json(__('super.general_settings.cancel')),
    csrfToken:            @json(csrf_token()),
    routes: {
        backupStore:       @json(route('super.settings.general.backup.store')),
        backupDownloadTpl: @json(route('super.settings.general.backup.download', ['filename' => '__FILENAME__'])),
        backupDestroyTpl:  @json(route('super.settings.general.backup.destroy', ['filename' => '__FILENAME__'])),
    },
};
</script>
<script src="{{ asset('assets_super/js/settings-general.js') }}?v={{ time() }}"></script>
@endpush

@endsection
