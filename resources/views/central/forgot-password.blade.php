<!DOCTYPE html>
@php
    $isRtl = in_array(app()->getLocale(), ['ar', 'he', 'fa', 'ur']);
    $generalSettings = \App\Models\Central\GeneralSetting::instance();
    $appName = $generalSettings->app_name ?: 'Stocky';
@endphp
<html lang="{{ app()->getLocale() }}" @if($isRtl) dir="rtl" @endif>
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>{{ __('landing.forgot_title') }} — {{ $appName }}</title>
    @php $faviconUrl = $generalSettings->getFaviconUrl(); @endphp
    <link rel="icon" href="{{ $faviconUrl ?: asset('images/super/settings/favicon.ico') }}" type="image/x-icon">
    <link href="{{ asset('assets_super/css/plus-jakarta-sans.css') }}" rel="stylesheet">
    <link href="{{ asset('assets_super/css/auth.css') }}" rel="stylesheet">
</head>
<body>

    <div class="login-hero">
        <div class="hero-shapes">
            <div class="shape shape-1"></div>
            <div class="shape shape-2"></div>
            <div class="shape shape-3"></div>
        </div>
        <div class="hero-content">
            <div class="hero-icon">
                <svg viewBox="0 0 24 24" fill="none" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                    <rect x="3" y="11" width="18" height="11" rx="2" ry="2"/>
                    <path d="M7 11V7a5 5 0 0 1 10 0v4"/>
                </svg>
            </div>
            <h2>{{ __('landing.forgot_title') }}</h2>
            <p>{{ __('landing.forgot_subtitle') }}</p>
        </div>
    </div>

    <div class="login-panel">
        <div class="login-form-wrapper">

            <a class="brand-logo" href="{{ route('central.welcome') }}">
                <div class="logo-icon">
                    <img src="{{ $generalSettings->getLogoUrl() ?: asset('images/logo-default.png') }}" alt="{{ $appName }}">
                </div>
                <span>{{ $appName }}</span>
            </a>

            <div class="form-heading">
                <h1>{{ __('landing.forgot_title') }}</h1>
                <p>{{ __('landing.forgot_subtitle') }}</p>
            </div>

            @if (session('status'))
                <div class="login-alert login-alert-success">
                    <svg viewBox="0 0 24 24" fill="none" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                        <path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"/>
                        <polyline points="22 4 12 14.01 9 11.01"/>
                    </svg>
                    <span>{{ session('status') }}</span>
                </div>
            @endif

            @if ($errors->any())
                <div class="login-alert">
                    <svg viewBox="0 0 24 24" fill="none" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                        <circle cx="12" cy="12" r="10"/>
                        <line x1="12" y1="8" x2="12" y2="12"/>
                        <line x1="12" y1="16" x2="12.01" y2="16"/>
                    </svg>
                    <span>{{ $errors->first() }}</span>
                </div>
            @endif

            <form method="POST" action="{{ route('central.password.email') }}">
                @csrf

                <div class="form-group">
                    <label for="email">{{ __('landing.email_address') }}</label>
                    <div class="input-wrapper">
                        <input
                            id="email"
                            name="email"
                            type="email"
                            class="{{ $errors->has('email') ? 'is-invalid' : '' }}"
                            value="{{ old('email') }}"
                            required
                            autofocus
                            autocomplete="email"
                            placeholder="Enter Your Email"
                        >
                        <svg class="input-icon" viewBox="0 0 24 24" fill="none" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                            <rect x="2" y="4" width="20" height="16" rx="2"/>
                            <path d="m22 7-8.97 5.7a1.94 1.94 0 0 1-2.06 0L2 7"/>
                        </svg>
                    </div>
                </div>

                <button type="submit" class="btn-login" id="btn-submit">
                    <span class="btn-text">{{ __('landing.send_reset_link') }}</span>
                    <svg class="btn-arrow" viewBox="0 0 24 24" fill="none" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round">
                        <line x1="5" y1="12" x2="19" y2="12"/>
                        <polyline points="12 5 19 12 12 19"/>
                    </svg>
                    <span class="spinner"></span>
                </button>
            </form>

            <div class="divider"><span>{{ __('landing.or') }}</span></div>

            <a class="back-home" href="{{ route('central.login') }}">
                <svg viewBox="0 0 24 24" fill="none" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                    <line x1="19" y1="12" x2="5" y2="12"/>
                    <polyline points="12 19 5 12 12 5"/>
                </svg>
                {{ __('landing.back_to_login') }}
            </a>

            <div class="login-footer">
                &copy; {{ date('Y') }} {{ $appName }}. All rights reserved.
            </div>
        </div>
    </div>

    <script src="{{ asset('assets_super/js/forgot-password.js') }}"></script>
</body>
</html>
