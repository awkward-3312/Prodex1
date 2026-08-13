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
    <meta name="description" content="{{ __('landing.admin_login') }} — {{ $appName }}">
    <title>{{ __('landing.admin_login') }} — {{ $appName }}</title>
    @php $faviconUrl = $generalSettings->getFaviconUrl(); @endphp
    <link rel="icon" href="{{ $faviconUrl ?: asset('images/super/settings/favicon.ico') }}" type="image/x-icon">
    <link href="{{ asset('assets_super/css/plus-jakarta-sans.css') }}" rel="stylesheet">
    <link href="{{ asset('assets_super/css/auth.css') }}" rel="stylesheet">
</head>
<body>

    {{-- Left decorative panel --}}
    <div class="login-hero">
        <div class="hero-shapes">
            <div class="shape shape-1"></div>
            <div class="shape shape-2"></div>
            <div class="shape shape-3"></div>
            <div class="shape shape-4"></div>
        </div>
        <div class="hero-content">
            <div class="hero-icon">
                <svg viewBox="0 0 24 24" fill="none" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                    <path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/>
                </svg>
            </div>
            <h2>{{ __('landing.login_hero_title') }}</h2>
            <p>{{ __('landing.login_hero_desc') }}</p>
            <div class="hero-stats">
                <div class="stat">
                    <div class="stat-value">100%</div>
                    <div class="stat-label">{{ __('landing.stat_secure') }}</div>
                </div>
                <div class="stat">
                    <div class="stat-value">24/7</div>
                    <div class="stat-label">{{ __('landing.stat_access') }}</div>
                </div>
                <div class="stat">
                    <div class="stat-value">Real-time</div>
                    <div class="stat-label">{{ __('landing.stat_analytics') }}</div>
                </div>
            </div>
        </div>
    </div>

    {{-- Right form panel --}}
    <div class="login-panel">
        <div class="login-form-wrapper">

            <a class="brand-logo" href="{{ route('central.welcome') }}">
                <div class="logo-icon">
                    <img src="{{ $generalSettings->getLogoUrl() ?: asset('images/logo-default.png') }}" alt="{{ $appName }}">
                </div>
                <span>{{ $appName }}</span>
            </a>

            <div class="form-heading">
                <h1>{{ __('landing.welcome_back') }}</h1>
                <p>{{ __('landing.login_subtitle') }}</p>
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

            <form method="POST" action="{{ route('central.login.submit') }}" autocomplete="on">
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

                <div class="form-group">
                    <label for="password">{{ __('landing.password') }}</label>
                    <div class="input-wrapper">
                        <input
                            id="password"
                            name="password"
                            type="password"
                            class="{{ $errors->has('password') ? 'is-invalid' : '' }}"
                            required
                            autocomplete="current-password"
                            placeholder="Enter your password"
                        >
                        <svg class="input-icon" viewBox="0 0 24 24" fill="none" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                            <rect x="3" y="11" width="18" height="11" rx="2" ry="2"/>
                            <path d="M7 11V7a5 5 0 0 1 10 0v4"/>
                        </svg>
                        <button type="button" class="toggle-password" id="togglePasswordBtn" aria-label="Toggle password visibility">
                            <svg id="eye-open" viewBox="0 0 24 24" fill="none" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                <path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/>
                                <circle cx="12" cy="12" r="3"/>
                            </svg>
                            <svg id="eye-closed" viewBox="0 0 24 24" fill="none" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="display:none">
                                <path d="M17.94 17.94A10.07 10.07 0 0 1 12 20c-7 0-11-8-11-8a18.45 18.45 0 0 1 5.06-5.94"/>
                                <path d="M9.9 4.24A9.12 9.12 0 0 1 12 4c7 0 11 8 11 8a18.5 18.5 0 0 1-2.16 3.19"/>
                                <path d="m14.12 14.12a3 3 0 1 1-4.24-4.24"/>
                                <line x1="1" y1="1" x2="23" y2="23"/>
                            </svg>
                        </button>
                    </div>
                </div>

                <div class="form-options">
                    <label class="remember-check">
                        <input type="checkbox" name="remember" {{ old('remember') ? 'checked' : '' }}>
                        <span>{{ __('landing.remember_me') }}</span>
                    </label>
                    <a href="{{ route('central.password.request') }}" class="forgot-link">{{ __('landing.forgot_password') }}</a>
                </div>

                <button type="submit" class="btn-login" id="btn-login">
                    <span class="btn-text">{{ __('landing.sign_in_btn') }}</span>
                    <svg class="btn-arrow" viewBox="0 0 24 24" fill="none" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round">
                        <line x1="5" y1="12" x2="19" y2="12"/>
                        <polyline points="12 5 19 12 12 19"/>
                    </svg>
                    <span class="spinner"></span>
                </button>
            </form>

            <div class="divider"><span>{{ __('landing.or') }}</span></div>

            <a class="back-home" href="{{ route('central.welcome') }}">
                <svg viewBox="0 0 24 24" fill="none" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                    <line x1="19" y1="12" x2="5" y2="12"/>
                    <polyline points="12 19 5 12 12 5"/>
                </svg>
                {{ __('landing.back_to_homepage') }}
            </a>

            <div class="login-footer">
                &copy; {{ date('Y') }} {{ $appName }}. All rights reserved.
            </div>
        </div>
    </div>

    <script src="{{ asset('assets_super/js/login.js') }}"></script>
</body>
</html>
