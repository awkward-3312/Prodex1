<!DOCTYPE html>
@php $appName = \App\Models\Central\GeneralSetting::instance()->app_name ?: 'Stocky'; @endphp
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ __('central.WorkspaceReadyTitle') }} — {{ $appName }}</title>
    <link rel="icon" href="{{ asset('images/super/settings/favicon.ico') }}">
    <link href="{{ asset('assets_super/css/plus-jakarta-sans.css') }}" rel="stylesheet">
    <link href="{{ asset('assets_super/css/bootstrap-icons.min.css') }}" rel="stylesheet">
    <link href="{{ asset('assets_super/css/status-pages.css') }}" rel="stylesheet">
</head>
<body class="page-ready">
    <div class="ready-container">
        <div class="success-icon">
            <i class="bi bi-check-lg"></i>
        </div>

        <h1 class="ready-title">{{ __('central.YourWorkspaceIsReady') }}</h1>
        <p class="ready-subtitle">
            <strong>{{ $companyName }}</strong> {{ __('central.WorkspaceSetUpSuccessfully') }}<br>
            {{ __('central.YouCanNowLogIn') }}
        </p>

        {{-- Workspace URL --}}
        <div class="info-card">
            <div class="info-card-header">
                <i class="bi bi-globe2"></i> {{ __('central.Workspace') }}
            </div>
            <div class="info-card-body">
                <div class="info-row info-row-first-last">
                    <span class="info-label">{{ __('central.Subdomain') }}</span>
                    <span class="info-value">{{ $subdomain }}</span>
                </div>
                <div class="info-row">
                    <span class="info-label">{{ __('central.URL') }}</span>
                    <span class="info-value">
                        <a href="{{ $workspaceUrl }}" target="_blank" rel="noopener">{{ $workspaceUrl }}</a>
                    </span>
                </div>
            </div>
        </div>

        {{-- Admin credentials --}}
        <div class="info-card">
            <div class="info-card-header">
                <i class="bi bi-person-badge"></i> {{ __('central.AdminLoginCredentials') }}
            </div>
            <div class="info-card-body">
                <div class="info-row info-row-first">
                    <span class="info-label">{{ __('central.Email') }}</span>
                    <span class="info-value">
                        <span class="password-field">
                            <span class="password-text">{{ $adminEmail }}</span>
                            <button class="copy-btn" data-copy="{{ $adminEmail }}" title="{{ __('central.CopyEmail') }}">
                                <i class="bi bi-clipboard"></i>
                            </button>
                        </span>
                    </span>
                </div>
                <div class="info-row">
                    <span class="info-label">{{ __('central.Password') }}</span>
                    <span class="info-value">
                        @if($password)
                        <span class="password-field">
                            <span class="password-text" id="pwText">{{ $password }}</span>
                            <button class="copy-btn" data-copy="{{ $password }}" title="{{ __('central.CopyPassword') }}">
                                <i class="bi bi-clipboard"></i>
                            </button>
                        </span>
                        @else
                        <span class="text-muted-hint">
                            {{ __('central.UsePasswordFromRegistration') }}
                        </span>
                        @endif
                    </span>
                </div>
            </div>
        </div>

        @if($password)
        <div class="security-notice">
            <i class="bi bi-shield-exclamation"></i>
            <span>
                <strong>{{ __('central.SaveCredentialsNow') }}</strong>
                {{ __('central.PasswordNotShownAgain') }}
            </span>
        </div>
        @endif

        <div class="btn-group-ready">
            <a href="{{ $workspaceUrl }}" class="btn-ready btn-primary-ready">
                <i class="bi bi-box-arrow-up-right"></i> {{ __('central.OpenWorkspace') }}
            </a>
            <a href="{{ $loginUrl }}" class="btn-ready btn-outline-ready">
                <i class="bi bi-box-arrow-in-right"></i> {{ __('central.GoToLogin') }}
            </a>
        </div>

        <p class="ready-footer">
            <i class="bi bi-shield-lock-fill"></i>
            {{ __('central.WorkspaceSecuredReady') }} &copy; {{ date('Y') }} {{ $appName }}
        </p>
    </div>

    <script src="{{ asset('assets_super/js/workspace-ready.js') }}"></script>
</body>
</html>
