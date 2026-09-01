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
    <meta name="description" content="{{ __('landing.create_workspace') }} — {{ $appName }}">
    <title>{{ __('landing.create_workspace') }} — {{ $appName }}</title>
    @php $faviconUrl = $generalSettings->getFaviconUrl(); @endphp
    <link rel="icon" href="{{ $faviconUrl ?: asset('images/super/settings/favicon.ico') }}" type="image/x-icon">
    <link href="{{ asset('assets_super/css/plus-jakarta-sans.css') }}" rel="stylesheet">
    <link href="{{ asset('assets_super/css/register.css') }}" rel="stylesheet">
</head>
<body>

    {{-- Left decorative panel --}}
    <div class="reg-hero">
        <div class="hero-shapes">
            <div class="shape shape-1"></div>
            <div class="shape shape-2"></div>
            <div class="shape shape-3"></div>
            <div class="shape shape-4"></div>
        </div>
        <div class="hero-content">
            <div class="hero-icon">
                <svg viewBox="0 0 24 24" fill="none" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                    <path d="M21 16V8a2 2 0 0 0-1-1.73l-7-4a2 2 0 0 0-2 0l-7 4A2 2 0 0 0 3 8v8a2 2 0 0 0 1 1.73l7 4a2 2 0 0 0 2 0l7-4A2 2 0 0 0 21 16z"/>
                    <polyline points="3.27 6.96 12 12.01 20.73 6.96"/>
                    <line x1="12" y1="22.08" x2="12" y2="12"/>
                </svg>
            </div>
            <h2>{{ __('landing.launch_title') }}</h2>
            <p>{{ __('landing.launch_desc') }}</p>

            <div class="hero-features">
                @foreach([
                    'feat_subdomain',
                    'feat_pos',
                    'feat_warehouse',
                    'feat_ready',
                ] as $featKey)
                <div class="feat">
                    <div class="feat-icon">
                        <svg viewBox="0 0 24 24" fill="none" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                            <path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"/>
                            <polyline points="22 4 12 14.01 9 11.01"/>
                        </svg>
                    </div>
                    <span class="feat-text">{{ __('landing.' . $featKey) }}</span>
                </div>
                @endforeach
            </div>
        </div>
    </div>

    {{-- Right form panel --}}
    <div class="reg-panel">
        <div class="reg-form-wrapper">

            <a class="brand-logo" href="{{ route('central.welcome') }}">
                <div class="logo-icon">
                    <svg viewBox="0 0 24 24" fill="none" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round">
                        <path d="M13 2L3 14h9l-1 8 10-12h-9l1-8z"/>
                    </svg>
                </div>
                <span>{{ $appName }}</span>
            </a>

            <div class="form-heading">
                <h1>{{ __('landing.create_workspace') }}</h1>
                <p>{{ __('landing.reg_subtitle') }}</p>
            </div>

            <div class="steps-bar">
                <div class="step-dot active"></div>
                <div class="step-dot active"></div>
                <div class="step-dot active"></div>
            </div>

            @if ($errors->any())
                <div class="reg-alert">
                    <svg viewBox="0 0 24 24" fill="none" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                        <circle cx="12" cy="12" r="10"/>
                        <line x1="12" y1="8" x2="12" y2="12"/>
                        <line x1="12" y1="16" x2="12.01" y2="16"/>
                    </svg>
                    <div class="reg-alert-body">
                        <ul>
                            @foreach ($errors->all() as $error)
                                <li>{{ $error }}</li>
                            @endforeach
                        </ul>
                    </div>
                </div>
            @endif

            <form method="POST" action="{{ route('central.register.submit') }}" id="register-form" autocomplete="on">
                @csrf

                {{-- Subdomain --}}
                <div class="form-group">
                    <label for="subdomain">{{ __('landing.subdomain') }}</label>
                    <div class="input-wrapper">
                        <div class="subdomain-preview {{ $errors->has('subdomain') ? 'is-invalid' : '' }}">
                            <svg class="input-icon" viewBox="0 0 24 24" fill="none" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                <circle cx="12" cy="12" r="10"/>
                                <line x1="2" y1="12" x2="22" y2="12"/>
                                <path d="M12 2a15.3 15.3 0 0 1 4 10 15.3 15.3 0 0 1-4 10 15.3 15.3 0 0 1-4-10 15.3 15.3 0 0 1 4-10z"/>
                            </svg>
                            <input
                                id="subdomain"
                                name="subdomain"
                                type="text"
                                value="{{ old('subdomain') }}"
                                required
                                placeholder="your-company"
                                pattern="[a-z0-9][a-z0-9\-]*[a-z0-9]|[a-z0-9]"
                                autocomplete="off"
                            >
                            <span class="subdomain-suffix">.{{ preg_replace('/^www\./i', '', parse_url(config('app.url'), PHP_URL_HOST) ?? 'localhost') }}</span>
                        </div>
                    </div>
                    <p class="field-hint">{{ __('landing.subdomain_hint_reg') }}</p>
                    @error('subdomain') <p class="field-error">{{ $message }}</p> @enderror
                </div>

                {{-- Company name --}}
                <div class="form-group">
                    <label for="company_name">{{ __('landing.company_name') }}</label>
                    <div class="input-wrapper">
                        <input
                            id="company_name"
                            name="company_name"
                            type="text"
                            class="{{ $errors->has('company_name') ? 'is-invalid' : '' }}"
                            value="{{ old('company_name') }}"
                            required
                            placeholder="Acme Inc."
                        >
                        <svg class="input-icon" viewBox="0 0 24 24" fill="none" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                            <path d="M3 9l9-7 9 7v11a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2z"/>
                            <polyline points="9 22 9 12 15 12 15 22"/>
                        </svg>
                    </div>
                    @error('company_name') <p class="field-error">{{ $message }}</p> @enderror
                </div>

                {{-- Admin email --}}
                <div class="form-group">
                    <label for="admin_email">{{ __('landing.admin_email') }}</label>
                    <div class="input-wrapper">
                        <input
                            id="admin_email"
                            name="admin_email"
                            type="email"
                            class="{{ $errors->has('admin_email') ? 'is-invalid' : '' }}"
                            value="{{ old('admin_email') }}"
                            required
                            autocomplete="email"
                            placeholder="you@company.com"
                        >
                        <svg class="input-icon" viewBox="0 0 24 24" fill="none" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                            <rect x="2" y="4" width="20" height="16" rx="2"/>
                            <path d="m22 7-8.97 5.7a1.94 1.94 0 0 1-2.06 0L2 7"/>
                        </svg>
                    </div>
                    @error('admin_email') <p class="field-error">{{ $message }}</p> @enderror
                </div>

                {{-- Owner phone (optional) --}}
                <div class="form-group">
                    <label for="owner_phone">{{ __('landing.owner_phone') }}</label>
                    <div class="input-wrapper">
                        <input
                            id="owner_phone"
                            name="owner_phone"
                            type="tel"
                            class="{{ $errors->has('owner_phone') ? 'is-invalid' : '' }}"
                            value="{{ old('owner_phone') }}"
                            autocomplete="tel"
                            placeholder="+1 555 123 4567"
                        >
                        <svg class="input-icon" viewBox="0 0 24 24" fill="none" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                            <path d="M22 16.92v3a2 2 0 0 1-2.18 2 19.79 19.79 0 0 1-8.63-3.07 19.5 19.5 0 0 1-6-6 19.79 19.79 0 0 1-3.07-8.67A2 2 0 0 1 4.11 2h3a2 2 0 0 1 2 1.72 12.84 12.84 0 0 0 .7 2.81 2 2 0 0 1-.45 2.11L8.09 9.91a16 16 0 0 0 6 6l1.27-1.27a2 2 0 0 1 2.11-.45 12.84 12.84 0 0 0 2.81.7A2 2 0 0 1 22 16.92z"/>
                        </svg>
                    </div>
                    <p class="field-hint">{{ __('landing.owner_phone_hint') }}</p>
                    @error('owner_phone') <p class="field-error">{{ $message }}</p> @enderror
                </div>

                {{-- Password + Confirm --}}
                <div class="form-row">
                    <div class="form-group">
                        <label for="admin_password">{{ __('landing.password') }}</label>
                        <div class="input-wrapper">
                            <input
                                id="admin_password"
                                name="admin_password"
                                type="password"
                                class="{{ $errors->has('admin_password') ? 'is-invalid' : '' }}"
                                required
                                minlength="8"
                                autocomplete="new-password"
                                placeholder="{{ __('landing.min_chars') }}"
                            >
                            <svg class="input-icon" viewBox="0 0 24 24" fill="none" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                <rect x="3" y="11" width="18" height="11" rx="2" ry="2"/>
                                <path d="M7 11V7a5 5 0 0 1 10 0v4"/>
                            </svg>
                            <button type="button" class="toggle-password" data-target="admin_password" aria-label="Toggle password">
                                <svg class="eye-open" viewBox="0 0 24 24" fill="none" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                    <path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/>
                                    <circle cx="12" cy="12" r="3"/>
                                </svg>
                                <svg class="eye-closed" viewBox="0 0 24 24" fill="none" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="display:none">
                                    <path d="M17.94 17.94A10.07 10.07 0 0 1 12 20c-7 0-11-8-11-8a18.45 18.45 0 0 1 5.06-5.94"/>
                                    <path d="M9.9 4.24A9.12 9.12 0 0 1 12 4c7 0 11 8 11 8a18.5 18.5 0 0 1-2.16 3.19"/>
                                    <path d="m14.12 14.12a3 3 0 1 1-4.24-4.24"/>
                                    <line x1="1" y1="1" x2="23" y2="23"/>
                                </svg>
                            </button>
                        </div>
                        @error('admin_password') <p class="field-error">{{ $message }}</p> @enderror
                    </div>

                    <div class="form-group">
                        <label for="admin_password_confirmation">{{ __('landing.confirm_password') }}</label>
                        <div class="input-wrapper">
                            <input
                                id="admin_password_confirmation"
                                name="admin_password_confirmation"
                                type="password"
                                required
                                autocomplete="new-password"
                                placeholder="{{ __('landing.repeat') }}"
                            >
                            <svg class="input-icon" viewBox="0 0 24 24" fill="none" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                <path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"/>
                                <polyline points="22 4 12 14.01 9 11.01"/>
                            </svg>
                        </div>
                    </div>
                </div>

                {{-- Plan selection --}}
                @if($plans->isNotEmpty())
                @php $starterPlan = $plans->firstWhere('slug', 'starter'); @endphp
                <div class="form-group">
                    <label for="plan_id">{{ __('landing.plan') }}</label>
                    <div class="input-wrapper">
                        <select
                            id="plan_id"
                            name="plan_id"
                            class="{{ $errors->has('plan_id') ? 'is-invalid' : '' }}"
                        >
                            @if($starterPlan)
                            <option value=""
                                    data-free="{{ $starterPlan->isFree() ? '1' : '0' }}"
                                    data-price="{{ $starterPlan->price }}"
                                    data-yearly="{{ $starterPlan->getPriceForCycle('yearly') }}"
                                    {{ old('plan_id', request('plan')) === '' || old('plan_id', request('plan')) === null ? 'selected' : '' }}>
                                @if($starterPlan->isFree())
                                    {{ __('landing.default_plan') }}
                                @else
                                    {{ $starterPlan->name }} — {{ $currencySymbol }}{{ number_format($starterPlan->price, 2) }}/mo
                                @endif
                            </option>
                            @else
                            {{-- No public starter to default to — force an explicit choice. --}}
                            <option value="" disabled {{ old('plan_id', request('plan')) ? '' : 'selected' }}>
                                {{ __('landing.select_plan') }}
                            </option>
                            @endif
                            @foreach($plans->where('slug', '!=', 'starter') as $plan)
                                <option value="{{ $plan->id }}"
                                        data-free="{{ $plan->isFree() ? '1' : '0' }}"
                                        data-price="{{ $plan->price }}"
                                        data-yearly="{{ $plan->getPriceForCycle('yearly') }}"
                                        {{ old('plan_id', request('plan')) == $plan->id ? 'selected' : '' }}>
                                    {{ $plan->name }} — {{ $currencySymbol }}{{ number_format($plan->price, 2) }}/mo
                                </option>
                            @endforeach
                        </select>
                        <svg class="input-icon" viewBox="0 0 24 24" fill="none" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                            <rect x="1" y="4" width="22" height="16" rx="2" ry="2"/>
                            <line x1="1" y1="10" x2="23" y2="10"/>
                        </svg>
                    </div>
                    @error('plan_id') <p class="field-error">{{ $message }}</p> @enderror
                </div>

                {{-- Billing cycle --}}
                <div class="form-group cycle-group" id="billing-cycle-group">
                    <label>{{ __('landing.billing_cycle') }}</label>
                    <div class="cycle-options">
                        @php $regCycle = old('billing_cycle', request('billing_cycle') === 'yearly' ? 'yearly' : 'monthly'); @endphp
                        <label class="cycle-option">
                            <input type="radio" name="billing_cycle" value="monthly" class="billing-cycle-radio" {{ $regCycle === 'monthly' ? 'checked' : '' }}>
                            <div class="cycle-card">
                                <span class="cycle-name">{{ __('landing.monthly') }}</span>
                                <span class="cycle-price" id="monthly-price"></span>
                            </div>
                        </label>
                        <label class="cycle-option">
                            <input type="radio" name="billing_cycle" value="yearly" class="billing-cycle-radio" {{ $regCycle === 'yearly' ? 'checked' : '' }}>
                            <div class="cycle-card">
                                <span class="cycle-name">{{ __('landing.yearly') }}</span>
                                <span class="cycle-price" id="yearly-price"></span>
                            </div>
                        </label>
                    </div>
                </div>
                @endif

                <button type="submit" class="btn-register" id="btn-register">
                    <span class="btn-text">{{ __('landing.create_workspace_btn') }}</span>
                    <svg class="btn-arrow" viewBox="0 0 24 24" fill="none" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round">
                        <line x1="5" y1="12" x2="19" y2="12"/>
                        <polyline points="12 5 19 12 12 19"/>
                    </svg>
                    <span class="spinner"></span>
                </button>
            </form>

            <a class="back-home" href="{{ route('central.welcome') }}">
                <svg viewBox="0 0 24 24" fill="none" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                    <line x1="19" y1="12" x2="5" y2="12"/>
                    <polyline points="12 19 5 12 12 5"/>
                </svg>
                {{ __('landing.back_to_homepage') }}
            </a>

            <div class="reg-footer">
                &copy; {{ date('Y') }} {{ $appName }}. All rights reserved.
            </div>
        </div>
    </div>

    <script>window.RegisterConfig = { currencySymbol: @json($currencySymbol) };</script>
    <script src="{{ asset('assets_super/js/register.js') }}"></script>
</body>
</html>
